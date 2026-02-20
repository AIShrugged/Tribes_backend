<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TeamPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->isOrganizationMember($organization);
    }

    public function view(User $user, Team $team): bool
    {
        return $user->isTeamMember($team);
    }

    public function create(User $user, int|Organization $organization): bool
    {
        return $user->isOrganizationManager($organization);
    }

    public function update(User $user, Team $team): bool
    {
        return $user->isOrganizationManager($team->organization);
    }

    public function destroy(User $user, Team $team): bool
    {
        return $user->isOrganizationManager($team->organization);
    }
}
