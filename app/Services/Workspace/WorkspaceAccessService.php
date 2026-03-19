<?php

namespace App\Services\Workspace;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class WorkspaceAccessService
{
    public function listAccessibleWorkspaces(
        User $user,
        ?string $ability = null,
        ?int $organizationId = null,
        ?int $teamId = null,
    ): Collection
    {
        $workspaceIds = $this->resolveAccessibleWorkspaceIds($user, $ability);

        if ($workspaceIds === []) {
            return new EloquentCollection();
        }

        $workspaces = Workspace::query()
            ->with(['organization:id,name', 'team:id,name,organization_id', 'owner:id,name,email', 'permissions'])
            ->whereIn('id', $workspaceIds)
            ->orderBy('organization_id')
            ->orderByRaw('team_id asc nulls first')
            ->orderBy('name')
            ->get();

        if ($organizationId === null && $teamId === null) {
            return $workspaces;
        }

        return new EloquentCollection(
            $workspaces
                ->filter(fn (Workspace $workspace): bool => $this->workspaceMatchesScope($workspace, $organizationId, $teamId))
                ->values()
                ->all()
        );
    }

    public function manifestForUser(
        User $user,
        ?string $ability = null,
        ?int $organizationId = null,
        ?int $teamId = null,
    ): Collection
    {
        return $this->listAccessibleWorkspaces($user, $ability, $organizationId, $teamId)->map(
            fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'scope_type' => $workspace->scope_type,
                'organization_id' => $workspace->organization_id,
                'team_id' => $workspace->team_id,
                'owner_user_id' => $workspace->owner_user_id,
                'root_prefix' => $workspace->root_prefix,
                'storage_disk' => $workspace->storage_disk,
                'permissions' => $this->abilitiesForUser($user, $workspace, $organizationId, $teamId),
            ]
        )->values();
    }

    public function abilitiesForUser(User $user, Workspace $workspace, ?int $organizationId = null, ?int $teamId = null): array
    {
        $abilities = [
            'list' => false,
            'read' => false,
            'write' => false,
            'delete' => false,
            'execute' => false,
            'admin' => false,
        ];

        if (! $this->workspaceMatchesScope($workspace, $organizationId, $teamId)) {
            return $abilities;
        }

        if ((int) $workspace->owner_user_id === (int) $user->id || $user->isOrganizationManager($workspace->organization_id)) {
            return [
                'list' => true,
                'read' => true,
                'write' => true,
                'delete' => true,
                'execute' => true,
                'admin' => true,
            ];
        }

        if ($workspace->scope_type === 'org_shared' && $user->isOrganizationMember($workspace->organization_id)) {
            $abilities['list'] = true;
            $abilities['read'] = true;
        }

        if ($workspace->scope_type === 'team_shared' && $workspace->team_id && $user->isTeamMember($workspace->team_id)) {
            $abilities['list'] = true;
            $abilities['read'] = true;
        }

        if ($workspace->scope_type === 'personal_shared' && (int) $workspace->owner_user_id !== (int) $user->id) {
            if ($workspace->team_id !== null && $user->isTeamMember($workspace->team_id)) {
                $abilities['list'] = true;
                $abilities['read'] = true;
            } elseif ($workspace->team_id === null && $user->isOrganizationMember($workspace->organization_id)) {
                $abilities['list'] = true;
                $abilities['read'] = true;
            }
        }

        $teamIds = $user->teams()->pluck('teams.id')->map(fn ($id) => (string) $id)->all();

        $permissions = $workspace->permissions()
            ->where(function ($query) use ($user, $teamIds): void {
                $query->where(function ($sub) use ($user): void {
                    $sub->where('principal_type', 'user')
                        ->where('principal_id', (string) $user->id);
                });

                if ($teamIds !== []) {
                    $query->orWhere(function ($sub) use ($teamIds): void {
                        $sub->where('principal_type', 'team')
                            ->whereIn('principal_id', $teamIds);
                    });
                }
            })
            ->get();

        foreach ($permissions as $permission) {
            $abilities['list'] = $abilities['list'] || $permission->can_list;
            $abilities['read'] = $abilities['read'] || $permission->can_read;
            $abilities['write'] = $abilities['write'] || $permission->can_write;
            $abilities['delete'] = $abilities['delete'] || $permission->can_delete;
            $abilities['execute'] = $abilities['execute'] || $permission->can_execute;
            $abilities['admin'] = $abilities['admin'] || $permission->can_admin;
        }

        return $abilities;
    }

    public function can(User $user, Workspace $workspace, string $ability, ?int $organizationId = null, ?int $teamId = null): bool
    {
        return $this->abilitiesForUser($user, $workspace, $organizationId, $teamId)[$ability] ?? false;
    }

    private function resolveAccessibleWorkspaceIds(User $user, ?string $ability = null): array
    {
        $organizationIds = $user->organizations()->pluck('organizations.id')->all();
        $managedOrganizationIds = $user->organizations()
            ->wherePivot('role', UserRole::MANAGER->value)
            ->pluck('organizations.id')
            ->all();
        $teamIds = $user->teams()->pluck('teams.id')->all();

        $workspaceIds = Workspace::query()
            ->where(function ($query) use ($user, $organizationIds, $managedOrganizationIds, $teamIds): void {
                $query->where('owner_user_id', $user->id);

                if ($organizationIds !== []) {
                    $query->orWhere(function ($sub) use ($organizationIds): void {
                        $sub->where('scope_type', 'org_shared')
                            ->whereIn('organization_id', $organizationIds);
                    });
                }

                if ($teamIds !== []) {
                    $query->orWhere(function ($sub) use ($teamIds): void {
                        $sub->where('scope_type', 'team_shared')
                            ->whereIn('team_id', $teamIds);
                    });

                    $query->orWhere(function ($sub) use ($teamIds): void {
                        $sub->where('scope_type', 'personal_shared')
                            ->whereIn('team_id', $teamIds);
                    });
                }

                if ($organizationIds !== []) {
                    $query->orWhere(function ($sub) use ($organizationIds): void {
                        $sub->where('scope_type', 'personal_shared')
                            ->whereNull('team_id')
                            ->whereIn('organization_id', $organizationIds);
                    });
                }

                if ($managedOrganizationIds !== []) {
                    $query->orWhereIn('organization_id', $managedOrganizationIds);
                }
            })
            ->pluck('id')
            ->all();

        $permissionQuery = WorkspacePermission::query()
            ->where(function ($query) use ($user, $teamIds): void {
                $query->where(function ($sub) use ($user): void {
                    $sub->where('principal_type', 'user')
                        ->where('principal_id', (string) $user->id);
                });

                if ($teamIds !== []) {
                    $query->orWhere(function ($sub) use ($teamIds): void {
                        $sub->where('principal_type', 'team')
                            ->whereIn('principal_id', array_map('strval', $teamIds));
                    });
                }
            });

        if ($ability !== null) {
            $column = $this->abilityColumn($ability);
            $permissionQuery->where($column, true);
        }

        $permissionWorkspaceIds = $permissionQuery->pluck('workspace_id')->all();

        return array_values(array_unique(array_merge($workspaceIds, $permissionWorkspaceIds)));
    }

    private function abilityColumn(string $ability): string
    {
        return match ($ability) {
            'list' => 'can_list',
            'read' => 'can_read',
            'write' => 'can_write',
            'delete' => 'can_delete',
            'execute' => 'can_execute',
            'admin' => 'can_admin',
            default => throw new \InvalidArgumentException("Unknown workspace ability [{$ability}]"),
        };
    }

    private function workspaceMatchesScope(Workspace $workspace, ?int $organizationId = null, ?int $teamId = null): bool
    {
        if ($organizationId !== null && (int) $workspace->organization_id !== $organizationId) {
            return false;
        }

        if ($teamId === null) {
            return true;
        }

        if ((int) ($workspace->team_id ?? 0) === $teamId) {
            return true;
        }

        return in_array($workspace->scope_type, ['org_shared', 'personal_shared'], true) && $workspace->team_id === null;
    }
}
