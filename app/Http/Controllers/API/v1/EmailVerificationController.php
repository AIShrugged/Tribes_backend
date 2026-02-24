<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;

#[Group('Authentication')]
class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService
    ) {}

    /**
     * Verify email address
     *
     * Verify the user's email address using the token from the verification email.
     * This endpoint always redirects to the frontend — it does not return JSON.
     *
     * @urlParam token string required The email verification token. Example: abc123xyz
     *
     * @response 302 scenario="Verified — redirects to /email-verified?status=success" <<binary>>
     * @response 302 scenario="Error — redirects with status=error&reason=invalid_token|expired|already_verified|server_error" <<binary>>
     */
    public function verify(string $token): RedirectResponse
    {
        $reason = null;

        try {
            // Check if token exists
            $user = $this->emailVerificationService->getUserByToken($token);

            if (!$user) {
                $reason = 'invalid_token';
            } elseif ($this->emailVerificationService->isTokenVerified($token)) {
                $reason = 'already_verified';
            } elseif ($this->emailVerificationService->isTokenExpired($token)) {
                $reason = 'expired';
            } elseif (!$this->emailVerificationService->verifyToken($token)) {
                $reason = 'invalid_token';
            }
        } catch (\Exception $e) {
            $reason = 'server_error';
        }

        $params = $reason
            ? ['status' => 'error', 'reason' => $reason]
            : ['status' => 'success'];

        $url = config('app.frontend_url') . '/email-verified?' . http_build_query($params);

        return redirect($url);
    }

    /**
     * Resend verification email
     *
     * Resend the verification email to the authenticated user.
     * Rate limited to 6 requests per minute.
     *
     * @response 200 scenario="Sent" {
     *   "message": "Verification email sent",
     *   "email": "alice@example.com",
     *   "expires_in_minutes": 30
     * }
     * @response 422 scenario="Already verified" {"message": "Email already verified"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    #[Authenticated]
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Check if already verified
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified',
            ], 422);
        }

        // Resend verification email
        $this->emailVerificationService->resendVerification($user);

        return response()->json([
            'message' => 'Verification email sent',
            'email' => $user->email,
            'expires_in_minutes' => config('app.email_verification_expiry', 30),
        ], 200);
    }
}
