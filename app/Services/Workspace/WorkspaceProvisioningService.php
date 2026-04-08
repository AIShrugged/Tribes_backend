<?php

namespace App\Services\Workspace;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkspaceProvisioningService
{
    public function createWorkspace(User $actor, array $attributes): Workspace
    {
        return DB::transaction(function () use ($actor, $attributes): Workspace {
            [$organization, $team, $owner] = $this->resolveContext($actor, $attributes);
            $scopeType = (string) $attributes['scope_type'];
            $this->assertCreateAllowed($actor, $organization, $team, $scopeType, $owner);

            $name = trim((string) $attributes['name']);
            $slug = $this->resolveUniqueSlug($organization->id, $team?->id, $owner?->id, $name, $attributes['slug'] ?? null);

            $workspace = Workspace::create([
                'organization_id' => $organization->id,
                'team_id' => $team?->id,
                'owner_user_id' => $owner?->id,
                'name' => $name,
                'slug' => $slug,
                'scope_type' => $scopeType,
                'root_prefix' => $this->buildRootPrefix($organization, $team, $scopeType, $slug),
                'storage_disk' => config('workspaces.disk', 's3'),
                'status' => 'active',
                'metadata' => is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : null,
            ]);

            if ($owner !== null) {
                $this->grantOrUpdatePermission($workspace, 'user', (string) $owner->id, [
                    'can_list' => true,
                    'can_read' => true,
                    'can_write' => true,
                    'can_delete' => true,
                    'can_execute' => true,
                    'can_admin' => true,
                ]);
            }

            return $workspace->refresh();
        });
    }

    public function updateWorkspace(User $actor, Workspace $workspace, array $attributes): Workspace
    {
        return DB::transaction(function () use ($actor, $workspace, $attributes): Workspace {
            if (! $this->isWorkspaceAdmin($actor, $workspace)) {
                throw new \RuntimeException('You do not have permission to administer this workspace.');
            }

            $updates = [];

            if (array_key_exists('name', $attributes)) {
                $updates['name'] = trim((string) $attributes['name']);
            }

            if (array_key_exists('slug', $attributes)) {
                $updates['slug'] = $this->resolveUniqueSlug(
                    $workspace->organization_id,
                    $workspace->team_id,
                    $workspace->owner_user_id,
                    $updates['name'] ?? $workspace->name,
                    $attributes['slug'],
                    $workspace->id,
                );
            }

            if (array_key_exists('status', $attributes)) {
                $updates['status'] = (string) $attributes['status'];
            }

            if (array_key_exists('metadata', $attributes) && is_array($attributes['metadata'])) {
                $updates['metadata'] = $attributes['metadata'];
            }

            if ($updates !== []) {
                $workspace->update($updates);
            }

            return $workspace->refresh();
        });
    }

    public function grantOrUpdatePermission(Workspace $workspace, string $principalType, string $principalId, array $abilities): WorkspacePermission
    {
        return WorkspacePermission::updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'principal_type' => $principalType,
                'principal_id' => $principalId,
            ],
            [
                'can_list' => (bool) ($abilities['can_list'] ?? false),
                'can_read' => (bool) ($abilities['can_read'] ?? false),
                'can_write' => (bool) ($abilities['can_write'] ?? false),
                'can_delete' => (bool) ($abilities['can_delete'] ?? false),
                'can_execute' => (bool) ($abilities['can_execute'] ?? false),
                'can_admin' => (bool) ($abilities['can_admin'] ?? false),
            ]
        );
    }

    public function deletePermission(WorkspacePermission $permission): void
    {
        $permission->delete();
    }

    public function isWorkspaceAdmin(User $user, Workspace $workspace): bool
    {
        return (new WorkspaceAccessService)->abilitiesForUser($user, $workspace)['admin'] === true;
    }

    private function resolveContext(User $actor, array $attributes): array
    {
        $organization = Organization::query()->findOrFail((int) $attributes['organization_id']);
        $team = isset($attributes['team_id']) ? Team::query()->findOrFail((int) $attributes['team_id']) : null;
        $owner = isset($attributes['owner_user_id']) ? User::query()->findOrFail((int) $attributes['owner_user_id']) : null;

        if ($team !== null && (int) $team->organization_id !== (int) $organization->id) {
            throw new \RuntimeException('Team does not belong to the selected organization.');
        }

        if ($owner !== null && ! $owner->isOrganizationMember($organization)) {
            throw new \RuntimeException('Workspace owner must be a member of the selected organization.');
        }

        if ($owner === null && (str_starts_with((string) $attributes['scope_type'], 'user_') || (string) $attributes['scope_type'] === 'personal_shared')) {
            $owner = $actor;
        }

        return [$organization, $team, $owner];
    }

    private function assertCreateAllowed(User $actor, Organization $organization, ?Team $team, string $scopeType, ?User $owner): void
    {
        if (! $actor->isOrganizationMember($organization)) {
            throw new \RuntimeException('You do not belong to this organization.');
        }

        if ($team !== null && ! $actor->isTeamMember($team)) {
            throw new \RuntimeException('You do not belong to this team.');
        }

        if (in_array($scopeType, ['org_shared', 'team_shared'], true) && ! $actor->isOrganizationMember($organization)) {
            throw new \RuntimeException('Only organization members can create shared workspaces.');
        }

        if (in_array($scopeType, ['user_private', 'user_team_private', 'personal_shared'], true) && $owner !== null && (int) $owner->id !== (int) $actor->id && ! $actor->isOrganizationMember($organization)) {
            throw new \RuntimeException('Only organization members can create private workspaces for other users.');
        }

        if ($scopeType === 'user_team_private' && $team !== null && $owner !== null && ! $owner->isTeamMember($team)) {
            throw ValidationException::withMessages([
                'owner_user_id' => ['Workspace owner must be a member of the selected team.'],
            ]);
        }

        if ($scopeType === 'personal_shared' && $owner === null) {
            throw ValidationException::withMessages([
                'owner_user_id' => ['personal_shared workspaces require an owner.'],
            ]);
        }

        if ($scopeType === 'personal_shared' && $team !== null && $owner !== null && ! $owner->isTeamMember($team)) {
            throw ValidationException::withMessages([
                'owner_user_id' => ['Workspace owner must be a member of the selected team.'],
            ]);
        }
    }

    private function buildRootPrefix(Organization $organization, ?Team $team, string $scopeType, string $slug): string
    {
        $root = 'workspaces';

        try {
            $configuredRoot = config('workspaces.storage_prefix', 'workspaces');
            $root = trim((string) $configuredRoot, '/') !== '' ? trim((string) $configuredRoot, '/') : 'workspaces';
        } catch (\Throwable) {
            $root = 'workspaces';
        }

        $segments = [$root, 'orgs', (string) $organization->id];

        if ($scopeType === 'org_shared') {
            $segments[] = 'shared';
            $segments[] = $slug;

            return implode('/', $segments);
        }

        if ($team !== null) {
            $segments[] = 'teams';
            $segments[] = (string) $team->id;
        }

        if ($scopeType === 'team_shared') {
            $segments[] = 'shared';
            $segments[] = $slug;

            return implode('/', $segments);
        }

        if ($scopeType === 'personal_shared') {
            $segments[] = 'personal-shared';
            $segments[] = $slug;

            return implode('/', $segments);
        }

        $segments[] = 'users';
        $segments[] = $slug;

        return implode('/', $segments);
    }

    private function resolveUniqueSlug(int $organizationId, ?int $teamId, ?int $ownerUserId, string $name, ?string $requestedSlug = null, ?string $ignoreId = null): string
    {
        $base = Str::slug(trim((string) ($requestedSlug !== null && $requestedSlug !== '' ? $requestedSlug : $name)));
        $base = $base !== '' ? $base : 'workspace';
        $slug = $base;
        $suffix = 1;

        while (
            Workspace::query()
                ->where('organization_id', $organizationId)
                ->where('team_id', $teamId)
                ->where('owner_user_id', $ownerUserId)
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
