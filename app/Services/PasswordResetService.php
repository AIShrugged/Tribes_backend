<?php

namespace App\Services;

use App\Domain\DTO\EmailDTO;
use App\Jobs\SendEmailJob;
use App\Models\PasswordResetToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class PasswordResetService
{
    public function sendResetLink(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return;
        }

        $plainToken = Str::random(64);
        $hashedToken = hash('sha256', $plainToken);

        $expiryMinutes = (int) config('app.password_reset_expiry', 60);
        $expiresAt = Carbon::now()->addMinutes($expiryMinutes);

        PasswordResetToken::updateOrCreate(
            ['email' => $user->email],
            [
                'token' => $hashedToken,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]
        );

        $resetUrl = config('app.frontend_url') . '/auth/password/reset?token=' . $plainToken;

        $htmlBody = View::make('emails.reset-password', [
            'userName' => $user->name,
            'resetUrl' => $resetUrl,
            'expiryMinutes' => $expiryMinutes,
        ])->render();

        $emailDto = new EmailDTO(
            from: config('email.from.address'),
            fromName: config('email.from.name'),
            to: [$user->email],
            subject: 'Reset Your Password',
            htmlBody: $htmlBody,
        );

        SendEmailJob::dispatch($emailDto);
    }

    public function resetPassword(string $plainToken, string $newPassword): bool
    {
        $hashedToken = hash('sha256', $plainToken);

        $record = PasswordResetToken::query()
            ->where('token', $hashedToken)
            ->valid()
            ->first();

        if (!$record) {
            return false;
        }

        $user = $record->user;

        if (!$user) {
            return false;
        }

        $user->update(['password' => Hash::make($newPassword)]);

        $record->delete();

        $this->sendPasswordChangedNotification($user);

        return true;
    }

    public function cleanupExpired(): int
    {
        return PasswordResetToken::query()
            ->where('expires_at', '<', now())
            ->delete();
    }

    private function sendPasswordChangedNotification(User $user): void
    {
        $htmlBody = View::make('emails.password-changed', [
            'userName' => $user->name,
        ])->render();

        $emailDto = new EmailDTO(
            from: config('email.from.address'),
            fromName: config('email.from.name'),
            to: [$user->email],
            subject: 'Your Password Has Been Changed',
            htmlBody: $htmlBody,
        );

        SendEmailJob::dispatch($emailDto);
    }
}
