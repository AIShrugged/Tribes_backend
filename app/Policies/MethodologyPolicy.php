<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MethodologyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->isOrganizationMember($organization);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Methodology $methodology): bool
    {
        return $methodology->isDefault()
            || $user->isMemberOfOneTeam($methodology->teams)
            || $user->isOrganizationManager($methodology->organization);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, int|Organization $organization): bool
    {
        return $user->isOrganizationManager($organization);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Methodology $methodology): bool
    {
        return $user->isOrganizationManager($methodology->organization);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Methodology $methodology): bool
    {
        return $user->isOrganizationManager($methodology->organization);
    }
}
