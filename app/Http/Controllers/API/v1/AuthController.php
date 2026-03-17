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
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

#[Group('Authentication', 'Registration, login, email verification, and personal API token management.')]
class AuthController extends Controller
{
    #[Endpoint(title: 'Register', description: 'Create a new user account and return an auth token. If invite token is valid, the user is attached to the team immediately.')]
    #[BodyParameter('name', 'Full user name.', required: true, type: 'string', example: 'Alice Johnson')]
    #[BodyParameter('email', 'User email.', required: true, type: 'string', example: 'alice@example.com')]
    #[BodyParameter('password', 'User password.', required: true, type: 'string', example: 'secret123')]
    #[BodyParameter('invite', 'Optional invite token from a team invite.', required: false, type: 'string', example: 'abc123xyz')]
    #[Response(
        201,
        'Registered user auth envelope.',
        type: 'array{success: bool, data: array{token: string, email_verification_sent: bool, invite_accepted?: bool, team_id?: int|null, organization_id?: int|null}, message: string, status: int, meta: array<string, mixed>}'
    )]
    #[Response(
        409,
        'User already exists.',
        type: 'array{success: bool, data: null, message: string, status: int, meta: array<string, mixed>}'
    )]
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

        $profileLinkingService->linkByEmail($user);

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
                $user->markEmailAsVerified();
            }
        }

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

    #[Endpoint(title: 'Login', description: 'Authenticate with email and password and return a new auth token. Existing authToken tokens are revoked first.')]
    #[BodyParameter('email', 'User email.', required: true, type: 'string', example: 'alice@example.com')]
    #[BodyParameter('password', 'User password.', required: true, type: 'string', example: 'secret123')]
    #[Response(
        201,
        'Login success envelope.',
        type: 'array{success: bool, data: array{token: string}, message: string, status: int, meta: array<string, mixed>}'
    )]
    #[Response(
        401,
        'Invalid credentials.',
        type: 'array{success: bool, data: null, message: string, status: int, meta: array<string, mixed>}'
    )]
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

    #[Endpoint(title: 'Create personal API token', description: 'Issue a new named personal token for the authenticated user. Maximum 3 tokens per user.')]
    #[BodyParameter('name', 'Token display name.', required: true, type: 'string', example: 'my-api-key')]
    #[Response(
        201,
        'Created token envelope.',
        type: 'array{success: bool, data: array{token: string, name: string}, message: string, status: int, meta: array<string, mixed>}'
    )]
    #[Response(
        422,
        'Token limit reached.',
        type: 'array{success: bool, data: null, message: string, status: int, meta: array<string, mixed>}'
    )]
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

    #[Endpoint(title: 'List personal API tokens', description: 'List active personal API tokens for the authenticated user.')]
    #[Response(
        200,
        'Token list envelope.',
        type: 'array{success: bool, data: array<int, array{id: int, name: string, created_at: string|null, last_used_at: string|null}>, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function tokens(Request $request): ApiResponse
    {
        $tokens = $request->user()->tokens()->get(['id', 'name', 'created_at', 'last_used_at']);

        return ApiResponse::list($tokens, $tokens->count());
    }

    #[Endpoint(title: 'Revoke personal API token', description: 'Delete one of the authenticated user personal API tokens by id.')]
    #[PathParameter('tokenId', 'Personal access token ID.', required: true, type: 'integer', example: 12)]
    #[Response(204, 'Token revoked.')]
    #[Response(
        404,
        'Token not found.',
        type: 'array{success: bool, data: null, message: string, status: int, meta: array<string, mixed>}'
    )]
    public function revokeToken(Request $request, int $tokenId): ApiResponse
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if (!$deleted) {
            return ApiResponse::notFound();
        }

        return ApiResponse::success(status: 204);
    }

    #[Endpoint(title: 'Logout', description: 'Revoke the current access token.')]
    #[Response(204, 'Logged out.')]
    public function logout(Request $request): ApiResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success('Logged out', status: 204);
    }
}
