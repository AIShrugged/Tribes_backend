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

        return $this->isMemberOfSameOrganization($requester, $targetUser);
    }

    public function canAccessTeamData(User $requester, Team $team): bool
    {
        return $requester->isOrganizationMember($team->organization);
    }

    public function canAccessOrganizationData(User $requester, Organization $organization): bool
    {
        return $requester->isOrganizationMember($organization);
    }

    public function getAccessibleUserIds(User $user): array
    {
        $userIds = [$user->id];

        $organizations = $user->organizations()->get();

        foreach ($organizations as $organization) {
            $orgUserIds = $organization->users()->pluck('users.id')->toArray();
            $userIds = array_merge($userIds, $orgUserIds);
        }

        return array_unique($userIds);
    }

    public function getAccessibleTeamIds(User $user): array
    {
        $teamIds = [];

        $organizations = $user->organizations()->get();

        foreach ($organizations as $organization) {
            $orgTeamIds = $organization->teams()->pluck('id')->toArray();
            $teamIds = array_merge($teamIds, $orgTeamIds);
        }

        return array_unique($teamIds);
    }

    public function getAccessibleOrganizationIds(User $user): array
    {
        return $user->organizations()
            ->pluck('organizations.id')
            ->toArray();
    }

    public function getUserRole(User $user): string
    {
        return $user->organizations()->exists()
            ? UserRole::MANAGER->value
            : UserRole::EMPLOYEE->value;
    }

    public function getAccessDescription(User $user): array
    {
        return [
            'role'             => $this->getUserRole($user),
            'description'      => 'Доступ к данным своих организаций',
            'user_ids'         => $this->getAccessibleUserIds($user),
            'team_ids'         => $this->getAccessibleTeamIds($user),
            'organization_ids' => $this->getAccessibleOrganizationIds($user),
        ];
    }

    private function isMemberOfSameOrganization(User $requester, User $targetUser): bool
    {
        $requesterOrgIds = $requester->organizations()
            ->pluck('organizations.id')
            ->toArray();

        if (empty($requesterOrgIds)) {
            return false;
        }

        return $targetUser->organizations()
            ->whereIn('organizations.id', $requesterOrgIds)
            ->exists();
    }
}
