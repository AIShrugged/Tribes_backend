<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TenantScopeValidator
{
    public function assertScopeIsValid(User $user, ?int $organizationId, ?int $teamId, bool $allowUnbound = true): void
    {
        if ($organizationId === null && $teamId === null) {
            if ($allowUnbound) {
                return;
            }

            throw ValidationException::withMessages([
                'organization_id' => ['Organization binding is required.'],
            ]);
        }

        if ($organizationId === null) {
            throw ValidationException::withMessages([
                'organization_id' => ['Organization is required when team_id is provided.'],
            ]);
        }

        $organization = Organization::query()->findOrFail($organizationId);

        if (! $user->isOrganizationMember($organization)) {
            throw ValidationException::withMessages([
                'organization_id' => ['You do not belong to the selected organization.'],
            ]);
        }

        if ($teamId === null) {
            return;
        }

        $team = Team::query()->findOrFail($teamId);

        if ((int) $team->organization_id !== (int) $organization->id) {
            throw ValidationException::withMessages([
                'team_id' => ['Team does not belong to the selected organization.'],
            ]);
        }

        if (! $user->isTeamMember($team)) {
            throw ValidationException::withMessages([
                'team_id' => ['You do not belong to the selected team.'],
            ]);
        }
    }

    public function assertUserCanManageOrganization(User $user, ?int $organizationId): void
    {
        if ($organizationId === null) {
            return;
        }

        $organization = Organization::query()->findOrFail($organizationId);

        if (! $user->isOrganizationManager($organization)) {
            throw ValidationException::withMessages([
                'organization_id' => ['Only organization managers can bind Telegram chats.'],
            ]);
        }
    }

    public function assertBound(?int $organizationId): void
    {
        if ($organizationId !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'organization_id' => ['Chat must be linked to an organization before it can be used.'],
        ]);
    }
}
