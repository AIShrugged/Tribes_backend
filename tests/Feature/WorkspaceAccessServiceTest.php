<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Services\Workspace\WorkspaceAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_owned_org_shared_team_shared_personal_shared_and_explicitly_granted_workspaces(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => 1,
            'name' => 'Core',
            'slug' => 'core',
        ]);
        $organization->users()->attach($user->id, ['role' => UserRole::EMPLOYEE->value]);
        $team->users()->attach($user->id);

        $owned = Workspace::create([
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'owner_user_id' => $user->id,
            'name' => 'Private',
            'slug' => 'private',
            'scope_type' => 'user_private',
            'root_prefix' => 'workspaces/orgs/acme/teams/core/users/private',
            'storage_disk' => 'local',
        ]);

        $orgShared = Workspace::create([
            'organization_id' => $organization->id,
            'name' => 'Org Shared',
            'slug' => 'org-shared',
            'scope_type' => 'org_shared',
            'root_prefix' => 'workspaces/orgs/acme/shared',
            'storage_disk' => 'local',
        ]);

        $teamShared = Workspace::create([
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Team Shared',
            'slug' => 'team-shared',
            'scope_type' => 'team_shared',
            'root_prefix' => 'workspaces/orgs/acme/teams/core/shared',
            'storage_disk' => 'local',
        ]);

        $granted = Workspace::create([
            'organization_id' => $organization->id,
            'name' => 'Granted',
            'slug' => 'granted',
            'scope_type' => 'org_shared',
            'root_prefix' => 'workspaces/orgs/acme/granted',
            'storage_disk' => 'local',
        ]);

        $personalShared = Workspace::create([
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'owner_user_id' => User::factory()->create()->id,
            'name' => 'Alice Shared',
            'slug' => 'alice-shared',
            'scope_type' => 'personal_shared',
            'root_prefix' => 'workspaces/orgs/acme/teams/core/personal-shared/alice-shared',
            'storage_disk' => 'local',
        ]);

        WorkspacePermission::create([
            'workspace_id' => $granted->id,
            'principal_type' => 'user',
            'principal_id' => (string) $user->id,
            'can_read' => true,
            'can_list' => true,
        ]);

        $service = app(WorkspaceAccessService::class);
        $manifest = $service->manifestForUser($user);

        $this->assertCount(5, $manifest);
        $this->assertSame(
            collect([$granted->id, $owned->id, $orgShared->id, $personalShared->id, $teamShared->id])->sort()->values()->all(),
            $manifest->pluck('id')->sort()->values()->all()
        );
        $this->assertTrue((bool) $manifest->firstWhere('id', $owned->id)['permissions']['write']);
        $this->assertTrue((bool) $manifest->firstWhere('id', $orgShared->id)['permissions']['read']);
        $this->assertFalse((bool) $manifest->firstWhere('id', $orgShared->id)['permissions']['write']);
        $this->assertTrue((bool) $manifest->firstWhere('id', $personalShared->id)['permissions']['read']);
        $this->assertFalse((bool) $manifest->firstWhere('id', $personalShared->id)['permissions']['write']);
    }
}
