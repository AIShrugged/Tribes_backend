<?php

namespace App\Services\Workspace;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkspaceBootstrapService
{
    public function __construct(
        private readonly WorkspaceProvisioningService $workspaceProvisioningService,
        private readonly WorkspaceService $workspaceService,
    ) {}

    public function ensureOrganizationDefaults(Organization $organization): Workspace
    {
        return DB::transaction(function () use ($organization): Workspace {
            $workspace = Workspace::query()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'team_id' => null,
                    'owner_user_id' => null,
                    'scope_type' => 'org_shared',
                    'slug' => 'shared',
                ],
                [
                    'name' => 'Organization Shared',
                    'root_prefix' => 'workspaces/orgs/'.$organization->id.'/shared/shared',
                    'storage_disk' => config('workspaces.disk', 's3'),
                    'status' => 'active',
                ]
            );

            $this->ensurePlaceholder($workspace);

            return $workspace;
        });
    }

    public function ensureTeamDefaults(Team $team): Workspace
    {
        return DB::transaction(function () use ($team): Workspace {
            $workspace = Workspace::query()->firstOrCreate(
                [
                    'organization_id' => $team->organization_id,
                    'team_id' => $team->id,
                    'owner_user_id' => null,
                    'scope_type' => 'team_shared',
                    'slug' => 'shared',
                ],
                [
                    'name' => 'Team Shared',
                    'root_prefix' => 'workspaces/orgs/'.$team->organization_id.'/teams/'.$team->id.'/shared/shared',
                    'storage_disk' => config('workspaces.disk', 's3'),
                    'status' => 'active',
                ]
            );

            $this->ensurePlaceholder($workspace);

            return $workspace;
        });
    }

    public function ensureUserTeamWorkspace(User $user, Team $team): Workspace
    {
        return DB::transaction(function () use ($user, $team): Workspace {
            $workspace = Workspace::query()->firstOrCreate(
                [
                    'organization_id' => $team->organization_id,
                    'team_id' => $team->id,
                    'owner_user_id' => $user->id,
                    'scope_type' => 'user_team_private',
                ],
                [
                    'name' => $user->name !== null && trim($user->name) !== '' ? "{$user->name} Workspace" : 'My Workspace',
                    'slug' => 'user-'.$user->id,
                    'root_prefix' => 'workspaces/orgs/'.$team->organization_id.'/teams/'.$team->id.'/users/user-'.$user->id,
                    'storage_disk' => config('workspaces.disk', 's3'),
                    'status' => 'active',
                ]
            );

            $this->workspaceProvisioningService->grantOrUpdatePermission($workspace, 'user', (string) $user->id, [
                'can_list' => true,
                'can_read' => true,
                'can_write' => true,
                'can_delete' => true,
                'can_execute' => true,
                'can_admin' => true,
            ]);

            $this->ensurePlaceholder($workspace);

            return $workspace;
        });
    }

    private function ensurePlaceholder(Workspace $workspace): void
    {
        $placeholderPath = '.workspace/.keep';
        $absolutePath = $this->workspaceService->resolveStoragePath($workspace, $placeholderPath);

        if (! Storage::disk($workspace->storage_disk)->exists($absolutePath)) {
            $this->workspaceService->writeFile($workspace, $placeholderPath, '');
        }
    }
}
