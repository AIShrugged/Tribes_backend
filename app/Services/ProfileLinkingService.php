<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\InsightProfile;
use App\Models\InsightSource;
use App\Models\Profile;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

class ProfileLinkingService
{
    /**
     * Link all anonymous google_calendar profiles that match the user's email.
     * Called automatically after registration.
     *
     * @return int Number of profiles linked
     */
    public function linkByEmail(User $user): int
    {
        $channelId = Channel::idFor('google_calendar');

        if (!$channelId) {
            return 0;
        }

        return Profile::where('channel_id', $channelId)
            ->where('channel_identifier', $user->email)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);
    }

    /**
     * Link a Telegram account to the user and create/update the corresponding Profile.
     *
     * @throws \RuntimeException if the telegram account belongs to another user
     */
    public function linkTelegramUser(User $user, int $telegramUserId): Profile
    {
        $telegramUser = TelegramUser::firstOrCreate(
            ['telegram_user_id' => $telegramUserId],
        );

        if ($telegramUser->user_id !== null && $telegramUser->user_id !== $user->id) {
            throw new \RuntimeException('This Telegram account is already linked to another user.', 409);
        }

        if ($telegramUser->user_id !== $user->id) {
            $telegramUser->update(['user_id' => $user->id]);
        }

        return $this->linkIdentity($user, 'telegram', (string) $telegramUserId);
    }

    /**
     * Link an arbitrary channel identity to the user.
     * Creates the profile if it doesn't exist.
     * Idempotent: returns existing profile if already linked to this user.
     *
     * @throws \RuntimeException with code 409 if identifier belongs to another user
     * @throws \RuntimeException with code 404 if channel not found
     */
    public function linkIdentity(User $user, string $channelName, string $identifier): Profile
    {
        $channelId = Channel::idFor($channelName);

        if (!$channelId) {
            throw new \RuntimeException("Channel '{$channelName}' not found.", 404);
        }

        $profile = Profile::where('channel_id', $channelId)
            ->where('channel_identifier', $identifier)
            ->first();

        if ($profile !== null) {
            if ($profile->user_id !== null && $profile->user_id !== $user->id) {
                throw new \RuntimeException('This identity is already linked to another user.', 409);
            }

            if ($profile->user_id === null) {
                $profile->update(['user_id' => $user->id]);
            }

            return $profile;
        }

        return Profile::create([
            'channel_id'         => $channelId,
            'channel_identifier' => $identifier,
            'user_id'            => $user->id,
        ]);
    }

    /**
     * Unlink a profile from the user.
     * If the profile has insight data, user_id is set to null (data is preserved).
     * If the profile has no insight data, the profile record is deleted entirely.
     */
    public function unlinkIdentity(User $user, Profile $profile): void
    {
        $hasInsightData = InsightProfile::where('profile_id', $profile->id)->exists()
            || InsightSource::where('profile_id', $profile->id)->exists();

        if ($hasInsightData) {
            $profile->update(['user_id' => null]);
        } else {
            $profile->delete();
        }
    }
}
