<?php

namespace App\Services\Chat;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;

class FollowupAccessService
{
    public function canAccessOwnData(User $user): bool
    {
        return true;
    }

    public function canAccessUserData(User $requester, User $targetUser): bool
    {
        if ($requester->id === $targetUser->id) {
            return true;
        }

        return $this->isManagerOfUser($requester, $targetUser);
    }

    public function canAccessTeamData(User $requester, Team $team): bool
    {
        return $requester->isOrganizationManager($team->organization);
    }

    public function canAccessOrganizationData(User $requester, Organization $organization): bool
    {
        return $requester->isOrganizationManager($organization);
    }

    public function getAccessibleUserIds(User $user): array
    {
        $userIds = [$user->id];

        $managedOrganizations = $user->organizations()
            ->wherePivot('role', UserRole::MANAGER->value)
            ->get();

        foreach ($managedOrganizations as $organization) {
            $orgUserIds = $organization->users()->pluck('users.id')->toArray();
            $userIds = array_merge($userIds, $orgUserIds);
        }

        return array_unique($userIds);
    }

    public function getAccessibleTeamIds(User $user): array
    {
        $teamIds = [];

        $managedOrganizations = $user->organizations()
            ->wherePivot('role', UserRole::MANAGER->value)
            ->get();

        foreach ($managedOrganizations as $organization) {
            $orgTeamIds = $organization->teams()->pluck('id')->toArray();
            $teamIds = array_merge($teamIds, $orgTeamIds);
        }

        return array_unique($teamIds);
    }

    public function getAccessibleOrganizationIds(User $user): array
    {
        return $user->organizations()
            ->wherePivot('role', UserRole::MANAGER->value)
            ->pluck('organizations.id')
            ->toArray();
    }

    public function getUserRole(User $user): string
    {
        $hasManagerRole = $user->organizations()
            ->wherePivot('role', UserRole::MANAGER->value)
            ->exists();

        return $hasManagerRole ? UserRole::MANAGER->value : UserRole::EMPLOYEE->value;
    }

    public function getAccessDescription(User $user): array
    {
        $role = $this->getUserRole($user);

        if ($role === UserRole::EMPLOYEE->value) {
            return [
                'role'        => $role,
                'description' => 'Доступ только к своим данным',
                'user_ids'    => [$user->id],
            ];
        }

        return [
            'role'             => $role,
            'description'      => 'Доступ к данным своих организаций',
            'user_ids'         => $this->getAccessibleUserIds($user),
            'team_ids'         => $this->getAccessibleTeamIds($user),
            'organization_ids' => $this->getAccessibleOrganizationIds($user),
        ];
    }

    private function isManagerOfUser(User $requester, User $targetUser): bool
    {
        $requesterManagedOrgIds = $requester->organizations()
            ->wherePivot('role', UserRole::MANAGER->value)
            ->pluck('organizations.id')
            ->toArray();

        if (empty($requesterManagedOrgIds)) {
            return false;
        }

        return $targetUser->organizations()
            ->whereIn('organizations.id', $requesterManagedOrgIds)
            ->exists();
    }
}
