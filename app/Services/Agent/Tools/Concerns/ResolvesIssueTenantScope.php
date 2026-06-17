<?php

namespace App\Services\Agent\Tools\Concerns;

use App\Models\Team;
use App\Models\User;

/**
 * Shared org/team resolution + membership check for issue-querying tools
 * (mirrors GetOpenIssuesTool::resolveTenantScope).
 */
trait ResolvesIssueTenantScope
{
    /**
     * @return array{success:bool, organization_id?:int|null, team_id?:int|null, error?:string}
     */
    protected function resolveTenantScope(?User $user, ?int $defaultOrgId, ?int $defaultTeamId, mixed $orgId, mixed $teamId): array
    {
        $orgId = $orgId !== null && $orgId !== '' ? (int) $orgId : $defaultOrgId;
        $teamId = $teamId !== null && $teamId !== '' ? (int) $teamId : $defaultTeamId;

        if ($orgId === null && $teamId === null) {
            return ['success' => false, 'error' => 'organization_id or team_id is required for task queries.'];
        }

        if ($teamId !== null) {
            $team = Team::query()->find($teamId);
            if (! $team) {
                return ['success' => false, 'error' => "Team {$teamId} not found."];
            }
            if ($orgId !== null && (int) $team->organization_id !== $orgId) {
                return ['success' => false, 'error' => 'team_id does not belong to organization_id.'];
            }
            $orgId ??= (int) $team->organization_id;
            if ($user !== null && ! $user->isTeamMember($team)) {
                return ['success' => false, 'error' => 'You do not have access to this team.'];
            }
        }

        if ($orgId !== null && $user !== null && ! $user->isOrganizationMember($orgId)) {
            return ['success' => false, 'error' => 'You do not have access to this organization.'];
        }

        return ['success' => true, 'organization_id' => $orgId, 'team_id' => $teamId];
    }
}
