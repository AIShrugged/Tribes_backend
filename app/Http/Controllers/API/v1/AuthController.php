<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\AuthRequest;
use App\Http\Requests\API\v1\CreateTokenRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\ProfileLinkingService;
use App\Services\TeamInvitationService;
use Illuminate\Http\JsonResponse;
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
    ): JsonResponse {
        $user = User::where('email', $request->getEmail())->first();

        if ($user) {
            return response()->json(['message' => 'User already exists.'], 409);
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

        $response = [
            'token' => $token,
            'email_verification_sent' => !$inviteAccepted,
        ];

        if ($inviteAccepted) {
            $response['invite_accepted'] = true;
            $response['team_id'] = $teamId;
            $response['organization_id'] = $organizationId;
        }

        return response()->json($response, 201);
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
    public function login(AuthRequest $request): JsonResponse
    {
        $user = User::where('email', $request->getEmail())->first();

        if (!$user || !Hash::check($request->getPass(), $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Delete ALL tokens to ensure complete session reset on login
        $user->tokens()->delete();

        $token = $user->createToken('authToken')->plainTextToken;

        return response()->json(['token' => $token], 201);
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
    public function createToken(CreateTokenRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->tokens()->count() >= 3) {
            return response()->json(['message' => 'Token limit reached. Maximum 3 tokens allowed.'], 422);
        }

        $name = $request->getName();
        $token = $user->createToken($name)->plainTextToken;

        return response()->json(['token' => $token, 'name' => $name], 201);
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
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Logged out',
            ], 204);
        }

        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out',
        ], 204);
    }
}
