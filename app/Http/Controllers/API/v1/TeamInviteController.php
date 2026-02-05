<?php

namespace App\Http\Controllers\API\v1;

use App\Domain\Errors\InviteAlreadyAcceptedError;
use App\Domain\Errors\InviteAlreadyExistsError;
use App\Domain\Errors\UserAlreadyInTeamError;
use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\v1\TeamInviteRequest;
use App\Http\Resources\API\v1\InviteResource;
use App\Http\Responses\ApiResponse;
use App\Models\Invite;
use App\Models\Team;
use App\Models\User;
use App\Services\TeamInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;

#[Group('Team Invitations')]
class TeamInviteController extends Controller
{
    public function __construct(
        private readonly TeamInvitationService $invitationService
    ) {}

    /**
     * List team invitations
     *
     * Get all invitations for a team. Only available for organization managers.
     */
    #[Authenticated]
    public function index(TeamInviteRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('viewAny', [Invite::class, $team]);

        $invites = $team->invites();

        $count = $invites->count();

        $invites = $invites->orderByDesc('created_at')
            ->offset($request->getOffset())
            ->limit($request->getLimit())
            ->get();

        return ApiResponse::list(InviteResource::collection($invites), $count);
    }

    /**
     * Send team invitation
     *
     * Invite a user to join a team by email. Only available for organization managers.
     */
    #[Authenticated]
    public function store(TeamInviteRequest $request, Team $team): ApiResponse
    {
        Gate::authorize('create', [Invite::class, $team]);

        $email = $request->getEmail();

        // Check if user is already in the team
        if ($this->invitationService->isUserInTeam($team, $email)) {
            throw AppException::fromErrorClass(UserAlreadyInTeamError::class, 409);
        }

        // Check if pending invite already exists
        if ($this->invitationService->hasPendingInvite($team, $email)) {
            throw AppException::fromErrorClass(InviteAlreadyExistsError::class, 409);
        }

        $invite = $this->invitationService->createInvite($team, $email, $request->user());
        $this->invitationService->sendInviteEmail($invite);

        return ApiResponse::success(
            message: 'Invitation sent',
            data: InviteResource::make($invite),
            status: 201
        );
    }

    /**
     * Cancel invitation
     *
     * Cancel a pending invitation. Only available for organization managers.
     */
    #[Authenticated]
    public function destroy(TeamInviteRequest $request, Team $team, Invite $invite): ApiResponse
    {
        Gate::authorize('delete', [$invite, $team]);

        if (!$invite->isPending()) {
            throw AppException::fromErrorClass(InviteAlreadyAcceptedError::class, 409);
        }

        $invite->markAsCancelled();

        return ApiResponse::success(message: 'Invitation cancelled');
    }

    /**
     * Accept invitation (redirect)
     *
     * Accept a team invitation. This endpoint redirects to the frontend
     * with the appropriate status and parameters.
     * If user not found - redirects to registration page with invite token.
     */
    public function accept(string $token): RedirectResponse
    {
        $invite = $this->invitationService->getInviteByToken($token);

        if (!$invite) {
            return $this->redirectWithError('invalid_token');
        }

        if ($invite->isCancelled()) {
            return $this->redirectWithError('cancelled');
        }

        if ($invite->isAccepted()) {
            return $this->redirectWithError('already_accepted');
        }

        if ($invite->isExpired()) {
            $invite->markAsExpired();
            return $this->redirectWithError('expired');
        }

        // Find user by email
        $user = User::where('email', $invite->email)->first();

        if (!$user) {
            // User not found - redirect to registration page
            $url = config('app.frontend_url') . '/auth/register?' . http_build_query([
                'invite' => $token,
                'email' => $invite->email,
            ]);

            return redirect($url);
        }

        // User found - accept invitation
        $this->invitationService->acceptInvite($invite, $user);

        $url = config('app.frontend_url') . '/invite-accepted?' . http_build_query([
            'status' => 'success',
            'team_id' => $invite->team_id,
        ]);

        return redirect($url);
    }

    /**
     * Build redirect URL with error status.
     */
    private function redirectWithError(string $reason): RedirectResponse
    {
        $url = config('app.frontend_url') . '/invite-accepted?' . http_build_query([
            'status' => 'error',
            'reason' => $reason,
        ]);

        return redirect($url);
    }
}
