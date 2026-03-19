<?php

namespace Tests\Feature;

use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;
    protected User $employee;
    protected Organization $organization;
    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        config(['workspaces.disk' => 'local']);
        Storage::fake('local');

        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $this->organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $this->team = Team::create([
            'organization_id' => $this->organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Platform',
            'slug' => 'platform',
        ]);

        $this->manager = User::factory()->create();
        $this->employee = User::factory()->create();

        $this->organization->users()->attach($this->manager->id, ['role' => 'manager']);
        $this->organization->users()->attach($this->employee->id, ['role' => 'employee']);
        $this->team->users()->attach($this->manager->id);
        $this->team->users()->attach($this->employee->id);
    }

    #[Test]
    public function creating_organization_and_team_bootstraps_shared_workspaces(): void
    {
        Sanctum::actingAs($this->manager);

        $orgResponse = $this->postJson('/api/v1/organizations', [
            'name' => 'Beta Org',
        ])->assertStatus(200);

        $organizationId = $orgResponse->json('data.id');

        $this->assertDatabaseHas('workspaces', [
            'organization_id' => $organizationId,
            'scope_type' => 'org_shared',
            'slug' => 'shared',
        ]);

        $teamResponse = $this->postJson('/api/v1/teams', [
            'organization_id' => $organizationId,
            'name' => 'Core Team',
        ])->assertStatus(200);

        $teamId = $teamResponse->json('data.id');

        $this->assertDatabaseHas('workspaces', [
            'organization_id' => $organizationId,
            'team_id' => $teamId,
            'scope_type' => 'team_shared',
            'slug' => 'shared',
        ]);
    }

    #[Test]
    public function manager_can_create_workspace_manage_files_and_grant_access(): void
    {
        Sanctum::actingAs($this->manager);

        $createResponse = $this->postJson('/api/v1/workspaces', [
            'organization_id' => $this->organization->id,
            'team_id' => $this->team->id,
            'owner_user_id' => $this->manager->id,
            'name' => 'Manager Notes',
            'scope_type' => 'user_team_private',
        ])->assertStatus(200);

        $workspaceId = $createResponse->json('data.id');
        $rootPrefix = $createResponse->json('data.root_prefix');

        $this->assertSame('user_team_private', $createResponse->json('data.scope_type'));
        $this->assertStringContainsString('/teams/'.$this->team->id.'/users/', str_replace('\\', '/', '/'.$rootPrefix));

        $this->postJson("/api/v1/workspaces/{$workspaceId}/directories", [
            'path' => 'notes/archive',
        ])->assertStatus(200)
            ->assertJsonPath('data.created', true);

        $this->putJson("/api/v1/workspaces/{$workspaceId}/file", [
            'path' => 'notes/today.txt',
            'contents' => 'ship workspace runtime',
        ])->assertStatus(200);

        $this->getJson("/api/v1/workspaces/{$workspaceId}/file?path=notes/today.txt")
            ->assertStatus(200)
            ->assertJsonPath('data.contents', 'ship workspace runtime');

        $this->getJson("/api/v1/workspaces/{$workspaceId}/file?path=/notes/today.txt")
            ->assertStatus(200)
            ->assertJsonPath('data.contents', 'ship workspace runtime')
            ->assertJsonPath('data.path', 'notes/today.txt');

        $this->putJson("/api/v1/workspaces/{$workspaceId}/file", [
            'path' => 'notes/large.txt',
            'contents' => str_repeat('x', 128),
        ])->assertStatus(200);

        $this->getJson("/api/v1/workspaces/{$workspaceId}/file?path=notes/large.txt&max_bytes=16")
            ->assertStatus(200)
            ->assertJsonPath('data.truncated', true)
            ->assertJsonPath('data.returned_bytes', 16)
            ->assertJsonPath('data.size_bytes', 128);

        $this->getJson("/api/v1/workspaces/{$workspaceId}/contents?path=notes")
            ->assertStatus(200)
            ->assertJsonFragment(['path' => 'notes/today.txt']);

        $this->getJson("/api/v1/workspaces/{$workspaceId}/file?path=notes")
            ->assertStatus(404);

        $this->postJson("/api/v1/workspaces/{$workspaceId}/permissions", [
            'principal_type' => 'user',
            'principal_id' => (string) $this->employee->id,
            'can_list' => true,
            'can_read' => true,
        ])->assertStatus(200)
            ->assertJsonPath('data.principal_id', (string) $this->employee->id);

        Sanctum::actingAs($this->employee);

        $this->getJson('/api/v1/workspaces')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $workspaceId]);

        $this->getJson("/api/v1/workspaces/{$workspaceId}/file?path=notes/today.txt")
            ->assertStatus(200)
            ->assertJsonPath('data.contents', 'ship workspace runtime');

        $this->putJson("/api/v1/workspaces/{$workspaceId}/file", [
            'path' => 'notes/blocked.txt',
            'contents' => 'nope',
        ])->assertStatus(403);

        Storage::disk('local')->assertExists($rootPrefix.'/notes/today.txt');
        Storage::disk('local')->assertExists($rootPrefix.'/notes/archive');
    }

    #[Test]
    public function manager_cannot_create_user_team_private_workspace_for_user_outside_team(): void
    {
        $outsider = User::factory()->create();
        $this->organization->users()->attach($outsider->id, ['role' => 'employee']);

        Sanctum::actingAs($this->manager);

        $this->postJson('/api/v1/workspaces', [
            'organization_id' => $this->organization->id,
            'team_id' => $this->team->id,
            'owner_user_id' => $outsider->id,
            'name' => 'Invalid Team Workspace',
            'scope_type' => 'user_team_private',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    #[Test]
    public function employee_can_create_personal_shared_workspace_for_self(): void
    {
        Sanctum::actingAs($this->employee);

        $response = $this->postJson('/api/v1/workspaces', [
            'organization_id' => $this->organization->id,
            'team_id' => $this->team->id,
            'name' => 'Daily Changelog',
            'scope_type' => 'personal_shared',
        ])->assertStatus(200);

        $workspaceId = $response->json('data.id');

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspaceId,
            'organization_id' => $this->organization->id,
            'team_id' => $this->team->id,
            'owner_user_id' => $this->employee->id,
            'scope_type' => 'personal_shared',
            'name' => 'Daily Changelog',
        ]);
    }

    #[Test]
    public function accepting_invite_bootstraps_user_team_workspace(): void
    {
        $invited = User::factory()->create(['email' => 'invited@example.com']);

        $service = app(\App\Services\TeamInvitationService::class);
        $invite = $service->createInvite($this->team, 'invited@example.com', $this->manager);

        $service->acceptInvite($invite, $invited);

        $this->assertDatabaseHas('workspaces', [
            'organization_id' => $this->organization->id,
            'scope_type' => 'org_shared',
            'slug' => 'shared',
        ]);

        $this->assertDatabaseHas('workspaces', [
            'organization_id' => $this->organization->id,
            'team_id' => $this->team->id,
            'scope_type' => 'team_shared',
            'slug' => 'shared',
        ]);

        $userWorkspace = Workspace::query()
            ->where('organization_id', $this->organization->id)
            ->where('team_id', $this->team->id)
            ->where('owner_user_id', $invited->id)
            ->where('scope_type', 'user_team_private')
            ->first();

        $this->assertNotNull($userWorkspace);
        $this->assertDatabaseHas('workspace_permissions', [
            'workspace_id' => $userWorkspace->id,
            'principal_type' => 'user',
            'principal_id' => (string) $invited->id,
            'can_write' => true,
            'can_execute' => true,
        ]);

        $personalSharedWorkspace = Workspace::query()
            ->where('organization_id', $this->organization->id)
            ->where('team_id', $this->team->id)
            ->where('owner_user_id', $invited->id)
            ->where('scope_type', 'personal_shared')
            ->first();

        $this->assertNotNull($personalSharedWorkspace);
        $this->assertDatabaseHas('workspace_permissions', [
            'workspace_id' => $personalSharedWorkspace->id,
            'principal_type' => 'user',
            'principal_id' => (string) $invited->id,
            'can_write' => true,
            'can_execute' => true,
        ]);
    }
}
