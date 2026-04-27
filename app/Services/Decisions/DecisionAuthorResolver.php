<?php

namespace App\Services\Decisions;

use App\Models\CalendarEvent;
use App\Models\Participant;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class DecisionAuthorResolver
{
    /**
     * Resolve a decision's author into a system User using a cascade.
     *
     * @return array{user_id: ?int, profile_id: ?int, raw_name: ?string, matched_by: string}
     */
    public function resolve(CalendarEvent $event, ?string $authorName, ?int $speakerParticipantId = null): array
    {
        $rawName = $authorName ? trim($authorName) : null;

        if ($speakerParticipantId !== null) {
            $participant = Participant::find($speakerParticipantId);
            if ($participant && $participant->profile_id) {
                $profile = Profile::with('user')->find($participant->profile_id);
                if ($profile?->user_id) {
                    return $this->result($profile->user_id, $profile->id, $rawName, 'participant_profile');
                }
            }
        }

        if ($rawName === null || $rawName === '') {
            return $this->result(null, null, null, 'no_author_name');
        }

        $eventProfiles = $event->profiles()->with('user', 'channel')->get();

        $resolved = $this->matchAgainstProfiles($eventProfiles, $rawName);
        if ($resolved !== null) {
            return $this->result($resolved['user_id'], $resolved['profile_id'], $rawName, 'event_profile_pivot');
        }

        $resolved = $this->matchAgainstProfilesByEmail($eventProfiles, $rawName);
        if ($resolved !== null) {
            return $this->result($resolved['user_id'], $resolved['profile_id'], $rawName, 'event_profile_email');
        }

        $globalUser = $this->matchGlobalUserByName($rawName);
        if ($globalUser !== null) {
            return $this->result($globalUser->id, null, $rawName, 'global_user_name');
        }

        return $this->result(null, null, $rawName, 'unresolved');
    }

    /** @param Collection<int, Profile> $profiles */
    private function matchAgainstProfiles(Collection $profiles, string $name): ?array
    {
        foreach ($profiles as $profile) {
            if (! $profile->user_id || ! $profile->user) {
                continue;
            }
            if ($this->namesMatch($profile->user->name ?? '', $name)) {
                return ['user_id' => $profile->user_id, 'profile_id' => $profile->id];
            }
        }

        return null;
    }

    /**
     * For google_calendar profiles where user_id is NULL but channel_identifier is the email.
     *
     * @param Collection<int, Profile> $profiles
     */
    private function matchAgainstProfilesByEmail(Collection $profiles, string $name): ?array
    {
        $emailProfiles = $profiles->filter(
            fn (Profile $p) => ! $p->user_id
                && $p->channel?->name === 'google_calendar'
                && filter_var($p->channel_identifier, FILTER_VALIDATE_EMAIL)
        );

        foreach ($emailProfiles as $profile) {
            $user = User::where('email', $profile->channel_identifier)->first();
            if ($user && $this->namesMatch($user->name ?? '', $name)) {
                return ['user_id' => $user->id, 'profile_id' => $profile->id];
            }
        }

        return null;
    }

    private function matchGlobalUserByName(string $name): ?User
    {
        $normalized = $this->normalize($name);

        return User::all()->first(
            fn (User $u) => $this->namesMatch($u->name ?? '', $name)
                || str_contains($this->normalize($u->name ?? ''), $normalized)
                || str_contains($normalized, $this->normalize($u->name ?? ''))
        );
    }

    private function namesMatch(string $a, string $b): bool
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        $aParts = explode(' ', $a);
        $bParts = explode(' ', $b);

        return $aParts[0] === $bParts[0] && strlen($aParts[0]) >= 3;
    }

    private function normalize(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    private function result(?int $userId, ?int $profileId, ?string $rawName, string $matchedBy): array
    {
        return [
            'user_id'    => $userId,
            'profile_id' => $profileId,
            'raw_name'   => $rawName,
            'matched_by' => $matchedBy,
        ];
    }
}
