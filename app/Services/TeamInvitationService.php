<?php

namespace App\Services;

use App\Domain\DTO\EmailDTO;
use App\Enums\InviteStatus;
use App\Enums\UserRole;
use App\Jobs\SendEmailJob;
use App\Models\Invite;
use App\Models\Team;
use App\Models\User;
use App\Services\Workspace\WorkspaceBootstrapService;
use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class TeamInvitationService
{
    public function __construct(
        private readonly WorkspaceBootstrapService $workspaceBootstrapService,
    ) {}

    /**
     * Create a new team invitation.
     */
    public function createInvite(Team $team, string $email, User $invitedBy): Invite
    {
        $plainToken = Str::random(64);
        $hashedToken = hash('sha256', $plainToken);

        $expiryHours = config('app.team_invite_expiry', 24);
        $expiresAt = Carbon::now()->addHours($expiryHours);

        $invite = Invite::create([
            'organization_id' => $team->organization_id,
            'team_id' => $team->id,
            'invited_by_id' => $invitedBy->id,
            'email' => $email,
            'token' => $hashedToken,
            'status' => InviteStatus::PENDING,
            'expires_at' => $expiresAt,
        ]);

        $invite->plain_token = $plainToken;

        return $invite;
    }

    /**
     * Send invitation email.
     */
    public function sendInviteEmail(Invite $invite): void
    {
        $invite->load(['team', 'organization', 'invitedBy']);

        $invitationUrl = route('invites.accept', ['token' => $invite->plain_token]);
        $expiryHours = config('app.team_invite_expiry', 24);

        $htmlBody = View::make('emails.team-invitation', [
            'teamName' => $invite->team->name,
            'organizationName' => $invite->organization->name,
            'inviterName' => $invite->invitedBy->name,
            'invitationUrl' => $invitationUrl,
            'expiryHours' => $expiryHours,
        ])->render();

        $emailDto = new EmailDTO(
            from: config('email.from.address'),
            fromName: config('email.from.name'),
            to: [$invite->email],
            subject: "You're invited to join {$invite->team->name}",
            htmlBody: $htmlBody,
        );

        SendEmailJob::dispatch($emailDto);
    }

    /**
     * Accept invitation for a user.
     * Adds user to organization and team.
     */
    public function acceptInvite(Invite $invite, User $user): void
    {
        // Add user to organization if not already a member.
        // Membership service also attaches to default team + personal_shared workspace.
        if (! $user->isOrganizationMember($invite->organization_id)) {
            app(\App\Services\OrganizationMembershipService::class)
                ->add($invite->organization, $user, UserRole::EMPLOYEE);
        }

        // Add user to the explicit invite-target team (real team, separate from default).
        if (! $user->belongsToTeam($invite->team_id)) {
            $invite->team->users()->attach($user->id);
        }

        $this->workspaceBootstrapService->ensureTeamDefaults($invite->team);
        $this->workspaceBootstrapService->ensureUserTeamWorkspace($user, $invite->team);
        $this->workspaceBootstrapService->ensureUserPersonalSharedWorkspace($user, $invite->team);

        $invite->markAsAccepted();
    }

    /**
     * Get invite by token.
     */
    public function getInviteByToken(string $token): ?Invite
    {
        $hashedToken = hash('sha256', $token);

        return Invite::where('token', $hashedToken)->first();
    }

    /**
     * Check if user is already in team.
     */
    public function isUserInTeam(Team $team, string $email): bool
    {
        return $team->users()->where('email', $email)->exists();
    }

    /**
     * Check if pending invite already exists.
     */
    public function hasPendingInvite(Team $team, string $email): bool
    {
        return Invite::where('team_id', $team->id)
            ->where('email', $email)
            ->pending()
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Cleanup expired invitations.
     */
    public function cleanupExpired(): int
    {
        return Invite::where('status', InviteStatus::PENDING)
            ->where('expires_at', '<', now())
            ->update(['status' => InviteStatus::EXPIRED]);
    }
}
