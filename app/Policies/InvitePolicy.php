<?php

namespace App\Policies;

use App\Models\Invite;
use App\Models\Team;
use App\Models\User;

class InvitePolicy
{
    /**
     * Determine whether the user can view any invites for the team.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->isOrganizationMember($team->organization);
    }

    /**
     * Determine whether the user can create invites for the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->isOrganizationMember($team->organization);
    }

    /**
     * Determine whether the user can delete the invite.
     */
    public function delete(User $user, Invite $invite, Team $team): bool
    {
        return $user->isOrganizationMember($team->organization);
    }
}