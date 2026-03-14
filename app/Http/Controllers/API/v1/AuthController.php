<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\AuthRequest;
use App\Http\Requests\API\v1\CreateTokenRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\ProfileLinkingService;
use App\Services\TeamInvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;

#[Group('Authentication')]
class AuthController extends Controller
{
    /**
     * Register
     *
     * Create a new user account. Returns an auth token on success.
     * If an invite token is provided and valid, the user is added to the team
     * and the email is marked as verified without sending a verification email.
     *
     *
     * @response 201 scenario="Registered (standard)" {
     *   "token": "1|abc123token",
     *   "email_verification_sent": true
     * }
     * @response 201 scenario="Registered via invite" {
     *   "token": "1|abc123token",
     *   "email_verification_sent": false,
     *   "invite_accepted": true,
     *   "team_id": 2,
     *   "organization_id": 1
     * }
     * @response 409 scenario="User already exists" {"message": "User already exists."}
     */
    public function register(
        AuthRequest $request,
        EmailVerificationService $emailVerificationService,
        TeamInvitationService $teamInvitationService,
        ProfileLinkingService $profileLinkingService
    ): ApiResponse {
        $user = User::where('email', $request->getEmail())->first();

        if ($user) {
            return ApiResponse::error('User already exists.', status: 409);
        }

        $user = User::create($request->validated());
        $token = $user->createToken('authToken')->plainTextToken;

        // Link any anonymous profiles collected before registration
        $profileLinkingService->linkByEmail($user);

        // Handle invite token if provided
        $inviteAccepted = false;
        $teamId = null;
        $organizationId = null;

        if ($inviteToken = $request->getInviteToken()) {
            $invite = $teamInvitationService->getInviteByToken($inviteToken);

            if ($invite && $invite->isPending() && !$invite->isExpired() && $invite->email === $user->email) {
                $teamInvitationService->acceptInvite($invite, $user);
                $inviteAccepted = true;
                $teamId = $invite->team_id;
                $organizationId = $invite->organization_id;

                // Mark email as verified when registering via invite
                $user->markEmailAsVerified();
            }
        }

        // Send verification email only if not registered via invite
        if (!$inviteAccepted) {
            $emailVerificationService->sendVerificationEmail($user);
        }

        $data = [
            'token' => $token,
            'email_verification_sent' => !$inviteAccepted,
        ];

        if ($inviteAccepted) {
            $data['invite_accepted'] = true;
            $data['team_id'] = $teamId;
            $data['organization_id'] = $organizationId;
        }

        return ApiResponse::success(data: $data, status: 201);
    }

    /**
     * Login
     *
     * Authenticate with email and password. Returns an auth token on success.
     * All previous tokens for the user are revoked before issuing a new one.
     *
     *
     * @response 201 scenario="OK" {"token": "1|abc123token"}
     * @response 401 scenario="Invalid credentials" {"message": "Invalid credentials"}
     */
    public function login(AuthRequest $request): ApiResponse
    {
        $user = User::where('email', $request->getEmail())->first();

        if (!$user || !Hash::check($request->getPass(), $user->password)) {
            return ApiResponse::error('Invalid credentials', status: 401);
        }

        $user->tokens()->where('name', 'authToken')->delete();

        $token = $user->createToken('authToken')->plainTextToken;

        return ApiResponse::success(data: ['token' => $token], status: 201);
    }

    /**
     * Create token
     *
     * Issue a new named token for the authenticated user. Maximum 3 tokens per user.
     *
     * @response 201 scenario="OK" {"token": "2|abc123token", "name": "my-api-key"}
     * @response 422 scenario="Token limit reached" {"message": "Token limit reached. Maximum 3 tokens allowed."}
     */
    #[Authenticated]
    public function createToken(CreateTokenRequest $request): ApiResponse
    {
        $user = $request->user();

        if ($user->tokens()->count() >= 3) {
            return ApiResponse::error('Token limit reached. Maximum 3 tokens allowed.', status: 422);
        }

        $name = $request->getName();
        $token = $user->createToken($name)->plainTextToken;

        return ApiResponse::success(data: ['token' => $token, 'name' => $name], status: 201);
    }

    /**
     * List tokens
     *
     * List all active tokens for the authenticated user.
     *
     * @response 200 scenario="OK" [{"id": 1, "name": "authToken", "created_at": "2026-01-01T00:00:00.000000Z", "last_used_at": null}]
     */
    #[Authenticated]
    public function tokens(Request $request): ApiResponse
    {
        $tokens = $request->user()->tokens()->get(['id', 'name', 'created_at', 'last_used_at']);

        return ApiResponse::list($tokens, $tokens->count());
    }

    /**
     * Revoke token
     *
     * Revoke a specific token by its ID. Only tokens belonging to the authenticated user can be revoked.
     *
     * @response 204 scenario="OK"
     * @response 404 scenario="Not found" {"message": "Token not found."}
     */
    #[Authenticated]
    public function revokeToken(Request $request, int $tokenId): ApiResponse
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if (!$deleted) {
            return ApiResponse::notFound();
        }

        return ApiResponse::success(status: 204);
    }

    /**
     * Logout
     *
     * Revoke the current access token.
     *
     * @response 204 scenario="OK" {"message": "Logged out"}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
     */
    #[Authenticated]
    public function logout(Request $request): ApiResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success('Logged out', status: 204);
    }
}
