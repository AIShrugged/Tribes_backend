<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\TeamUser;
use App\Models\User;

class TeamUserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->isOrganizationMember($team->organization) || $user->isTeamMember($team);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TeamUser $teamUser, Team $team): bool
    {
        return $user->isOrganizationMember($team->organization) || $user->isTeamMember($team);
    }

    public function kick(User $user, TeamUser $teamUser, Team $team): bool
    {
        return $user->isOrganizationMember($team->organization);
    }
}
