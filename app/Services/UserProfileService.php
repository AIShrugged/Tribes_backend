<?php

namespace App\Services;

use App\Domain\DTO\EmailDTO;
use App\Domain\Errors\InvalidCurrentPasswordError;
use App\Exceptions\AppException;
use App\Jobs\SendEmailJob;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Throwable;

class UserProfileService
{
    /**
     * Update the user's profile data.
     *
     * Applies name change and/or password change in a single atomic save.
     * When a new password is provided, verifies the current password first.
     * Dispatches a password-changed email notification after a successful password update.
     *
     * @throws AppException with INVALID_CURRENT_PASSWORD (422) if current password is wrong.
     */
    public function update(
        User $user,
        ?string $name,
        ?string $currentPassword,
        ?string $newPassword,
    ): User {
        if ($newPassword !== null) {
            if (!Hash::check($currentPassword, $user->password)) {
                throw AppException::fromErrorClass(InvalidCurrentPasswordError::class, status: 422);
            }
        }

        if ($name !== null) {
            $user->name = $name;
        }

        if ($newPassword !== null) {
            $user->password = $newPassword;
        }

        $user->save();

        if ($newPassword !== null) {
            $this->revokeOtherTokens($user);

            try {
                $this->dispatchPasswordChangedNotification($user);
            } catch (Throwable $e) {
                Log::error('Failed to dispatch password changed notification', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $user;
    }

    private function revokeOtherTokens(User $user): void
    {
        $currentTokenId = $user->currentAccessToken()?->id;

        $user->tokens()
            ->when($currentTokenId, fn($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();
    }

    private function dispatchPasswordChangedNotification(User $user): void
    {
        $htmlBody = View::make('emails.password-changed', [
            'userName' => $user->name,
        ])->render();

        $emailDto = new EmailDTO(
            from: config('email.from.address'),
            fromName: config('email.from.name'),
            to: [$user->email],
            subject: 'Your password has been changed',
            htmlBody: $htmlBody,
        );

        SendEmailJob::dispatch($emailDto);

        Log::info('Password changed notification dispatched', ['user_id' => $user->id]);

    }
}
