<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\AuthRequest;
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

    public function login(AuthRequest $request): JsonResponse
    {
        $user = User::where('email', $request->getEmail())->first();

        if (!$user || !Hash::check($request->getPass(), $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user->tokens()->delete();

        $token = $user->createToken('authToken')->plainTextToken;

        return response()->json(['token' => $token], 201);
    }

    #[Authenticated]
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Logged out',
            ], 204);
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out',
        ], 204);
    }
}
