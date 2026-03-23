<?php

namespace App\Services;

use App\Domain\DTO\EmailDTO;
use App\Jobs\SendEmailJob;
use App\Models\EmailVerification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class EmailVerificationService
{
    /**
     * Create a new email verification record for the user.
     */
    public function createVerification(User $user): EmailVerification
    {
        // Invalidate any existing verifications for this user
        EmailVerification::where('user_id', $user->id)
            ->whereNull('verified_at')
            ->delete();

        // Generate a random token
        $plainToken = Str::random(64);
        $hashedToken = hash('sha256', $plainToken);

        // Calculate expiry time
        $expiryMinutes = (int) config('app.email_verification_expiry', 30);
        $expiresAt = Carbon::now()->addMinutes($expiryMinutes);

        // Create verification record
        $verification = EmailVerification::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'token' => $hashedToken,
            'expires_at' => $expiresAt,
        ]);

        // Store plain token temporarily on the model for email generation
        $verification->plain_token = $plainToken;

        return $verification;
    }

    /**
     * Send verification email to the user.
     */
    public function sendVerificationEmail(User $user): void
    {
        // Create verification record
        $verification = $this->createVerification($user);

        // Build verification URL
        $verificationUrl = route('auth.email.verify', ['token' => $verification->plain_token]);

        // Get expiry time in minutes
        $expiryMinutes = config('app.email_verification_expiry', 30);

        // Render email HTML from blade template
        $htmlBody = View::make('emails.verify-email', [
            'userName' => $user->name,
            'verificationUrl' => $verificationUrl,
            'expiryMinutes' => $expiryMinutes,
        ])->render();

        // Build email DTO
        $emailDto = new EmailDTO(
            from: config('email.from.address'),
            fromName: config('email.from.name'),
            to: [$user->email],
            subject: 'Verify Your Email Address',
            htmlBody: $htmlBody,
        );

        // Dispatch email job
        SendEmailJob::dispatch($emailDto);
    }

    /**
     * Verify the token and mark the user's email as verified.
     */
    public function verifyToken(string $token): bool
    {
        // Hash the provided token
        $hashedToken = hash('sha256', $token);

        // Find valid verification record
        $verification = EmailVerification::query()
            ->where('token', $hashedToken)
            ->valid()
            ->first();

        if (!$verification) {
            return false;
        }

        // Mark verification as completed
        $verification->markAsVerified();

        // Mark user's email as verified
        $verification->user->markEmailAsVerified();

        return true;
    }

    /**
     * Get the user associated with a token.
     */
    public function getUserByToken(string $token): ?User
    {
        $hashedToken = hash('sha256', $token);

        $verification = EmailVerification::query()
            ->where('token', $hashedToken)
            ->first();

        return $verification?->user;
    }

    /**
     * Check if a token is expired.
     */
    public function isTokenExpired(string $token): bool
    {
        $hashedToken = hash('sha256', $token);

        $verification = EmailVerification::query()
            ->where('token', $hashedToken)
            ->first();

        if (!$verification) {
            return true;
        }

        return $verification->isExpired();
    }

    /**
     * Check if a token is already verified.
     */
    public function isTokenVerified(string $token): bool
    {
        $hashedToken = hash('sha256', $token);

        $verification = EmailVerification::query()
            ->where('token', $hashedToken)
            ->first();

        if (!$verification) {
            return false;
        }

        return $verification->isVerified();
    }

    /**
     * Resend verification email to the user.
     */
    public function resendVerification(User $user): void
    {
        $this->sendVerificationEmail($user);
    }

    /**
     * Clean up expired verification records.
     */
    public function cleanupExpired(): int
    {
        return EmailVerification::query()
            ->where('expires_at', '<', now())
            ->orWhereNotNull('verified_at')
            ->delete();
    }
}
