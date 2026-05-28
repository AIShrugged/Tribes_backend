<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;

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
        return $user->isOrganizationMember($organization);
    }

    public function update(User $user, Team $team): bool
    {
        return $user->isOrganizationMember($team->organization);
    }

    public function destroy(User $user, Team $team): bool
    {
        // Default team is invariant infrastructure (auto-provisioned, holds the
        // org-wide membership fallback). It cannot be deleted via API. See plan
        // docs/plans/2026-05-28-feat-default-team-per-organization-plan.md.
        if ($team->isDefault()) {
            return false;
        }

        return $user->isOrganizationMember($team->organization);
    }
}
