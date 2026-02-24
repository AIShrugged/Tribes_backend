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
     * The total count is returned in the `Items-Count` response header.
     *
     * @urlParam team integer required The Team ID. Example: 2
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "email": "bob@example.com",
     *       "status": "pending",
     *       "expires_at": "2026-02-17T10:00:00.000000Z",
     *       "accepted_at": null,
     *       "created_at": "2026-02-10T10:00:00.000000Z"
     *     }
     *   ],
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
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
     * An invitation email is sent to the specified address.
     *
     * @urlParam team integer required The Team ID. Example: 2
     *
     * @response 201 scenario="Invitation sent" {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "email": "bob@example.com",
     *     "status": "pending",
     *     "expires_at": "2026-02-17T10:00:00.000000Z",
     *     "accepted_at": null,
     *     "created_at": "2026-02-10T10:00:00.000000Z"
     *   },
     *   "message": "Invitation sent",
     *   "status": 201,
     *   "meta": {}
     * }
     * @response 409 scenario="User already in team" {"success": false, "message": "User is already in the team.", "code": "USER_ALREADY_IN_TEAM"}
     * @response 409 scenario="Invite already exists" {"success": false, "message": "Invite already exists.", "code": "INVITE_ALREADY_EXISTS"}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
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
     *
     * @urlParam team integer required The Team ID. Example: 2
     * @urlParam invite integer required The Invite ID. Example: 1
     *
     * @response 200 scenario="Cancelled" {
     *   "success": true,
     *   "data": null,
     *   "message": "Invitation cancelled",
     *   "status": 200,
     *   "meta": {}
     * }
     * @response 409 scenario="Invite already accepted" {"success": false, "message": "Invite already accepted.", "code": "INVITE_ALREADY_ACCEPTED"}
     * @response 403 scenario="Forbidden" {"message": "This action is unauthorized."}
     * @response 401 scenario="Unauthenticated" {"message": "Unauthenticated."}
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
     * Accept a team invitation using the token from the invitation email.
     * This endpoint redirects the browser to the frontend.
     * If the user account does not exist yet, redirects to the registration page with the invite token pre-filled.
     *
     * @urlParam token string required The invitation token from the email link. Example: abc123xyz
     *
     * @response 302 scenario="Accepted — redirects to frontend /invite-accepted?status=success&team_id=2" <<binary>>
     * @response 302 scenario="User not registered — redirects to /auth/register?invite=TOKEN&email=EMAIL" <<binary>>
     * @response 302 scenario="Error — redirects with status=error&reason=invalid_token|expired|cancelled|already_accepted" <<binary>>
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
