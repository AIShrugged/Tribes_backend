<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrganizationPolicy
{

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->isOrganizationMember($organization);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->isOrganizationMember($organization);
    }

    public function onboard(User $user, Organization $organization): bool
    {
        return $user->isOrganizationManager($organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->isOrganizationMember($organization);
    }
}
