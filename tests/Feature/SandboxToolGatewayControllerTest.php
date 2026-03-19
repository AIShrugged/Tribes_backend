<?php

namespace Tests\Feature;

use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePermission;
use App\Services\AgentTaskRunTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SandboxToolGatewayControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['workspaces.disk' => 'local']);
        Storage::fake('local');
    }

    #[Test]
    public function it_executes_allowlisted_tool_for_isolated_run(): void
    {
        $user = User::factory()->create();
        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Sandbox task',
            'prompt' => 'Test',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['get_current_user'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $response = $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'get_current_user',
            'arguments' => [],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.user.id', $user->id);
    }

    #[Test]
    public function it_rejects_non_allowlisted_tool_for_isolated_run(): void
    {
        $user = User::factory()->create();
        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Sandbox task',
            'prompt' => 'Test',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['get_current_user'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $response = $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'get_user_info',
            'arguments' => ['user_id' => $user->id],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.result.success', false);
    }

    #[Test]
    public function it_rejects_invalid_run_token(): void
    {
        $user = User::factory()->create();
        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Sandbox task',
            'prompt' => 'Test',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['get_current_user'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'get_current_user',
            'arguments' => [],
        ], [
            'X-Sandbox-Run-Token' => 'invalid-token',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid sandbox run token');
    }

    #[Test]
    public function it_executes_llm_completion_for_isolated_run(): void
    {
        $user = User::factory()->create();
        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Sandbox task',
            'prompt' => 'Inspect repo',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['get_current_user'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"output":"ok","summary":"ok","memory_candidates":[]}',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/llm-completions", [
            'messages' => [
                ['role' => 'user', 'content' => 'Inspect repository'],
            ],
            'system_prompt' => 'You are a sandboxed agent.',
            'max_tokens' => 512,
        ], [
            'X-Sandbox-Run-Token' => $token,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.message.role', 'assistant');
    }

    #[Test]
    public function it_lists_and_reads_workspace_files_for_allowlisted_workspace_tools(): void
    {
        [$user, $workspace] = $this->createWorkspaceContext();

        Storage::disk('local')->put($workspace->root_prefix.'/docs/plan.txt', 'workspace-plan');

        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Workspace task',
            'prompt' => 'Inspect workspace',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['list_workspaces', 'list_workspace_files', 'read_workspace_file'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'list_workspaces',
            'arguments' => [],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.workspaces.0.id', $workspace->id);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'list_workspace_files',
            'arguments' => [
                'workspace_id' => $workspace->id,
                'path' => 'docs',
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.listing.files.0.path', 'docs/plan.txt');

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'read_workspace_file',
            'arguments' => [
                'workspace_id' => $workspace->id,
                'path' => 'docs/plan.txt',
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.contents', 'workspace-plan');
    }

    #[Test]
    public function it_writes_workspace_file_for_allowlisted_tool_when_user_has_permission(): void
    {
        [$user, $workspace] = $this->createWorkspaceContext();

        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Workspace write task',
            'prompt' => 'Write workspace file',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['write_workspace_file'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'write_workspace_file',
            'arguments' => [
                'workspace_id' => $workspace->id,
                'path' => 'reports/out.txt',
                'contents' => 'generated by sandbox tool',
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.path', 'reports/out.txt');

        Storage::disk('local')->assertExists($workspace->root_prefix.'/reports/out.txt');
        $this->assertSame('generated by sandbox tool', Storage::disk('local')->get($workspace->root_prefix.'/reports/out.txt'));
    }

    #[Test]
    public function it_denies_workspace_write_when_user_only_has_read_access(): void
    {
        [$user, $workspace] = $this->createWorkspaceContext(grantWrite: false);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Workspace denied write task',
            'prompt' => 'Attempt write',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['write_workspace_file'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'write_workspace_file',
            'arguments' => [
                'workspace_id' => $workspace->id,
                'path' => 'reports/nope.txt',
                'contents' => 'should not write',
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', false)
            ->assertJsonPath('data.result.error', 'Workspace not found or access denied');

        Storage::disk('local')->assertMissing($workspace->root_prefix.'/reports/nope.txt');
    }

    #[Test]
    public function it_searches_copies_and_moves_workspace_files_through_gateway_tools(): void
    {
        [$user, $workspace] = $this->createWorkspaceContext();

        Storage::disk('local')->put($workspace->root_prefix.'/docs/alpha.txt', 'alpha');

        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Workspace file ops task',
            'prompt' => 'Search and reorganize files',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['search_workspace_files', 'copy_workspace_file', 'move_workspace_file'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'search_workspace_files',
            'arguments' => [
                'workspace_id' => $workspace->id,
                'query' => 'alpha',
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.results.0.path', 'docs/alpha.txt');

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'copy_workspace_file',
            'arguments' => [
                'workspace_id' => $workspace->id,
                'from_path' => 'docs/alpha.txt',
                'to_path' => 'docs/alpha-copy.txt',
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'move_workspace_file',
            'arguments' => [
                'workspace_id' => $workspace->id,
                'from_path' => 'docs/alpha-copy.txt',
                'to_path' => 'archive/alpha-copy.txt',
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true);

        Storage::disk('local')->assertExists($workspace->root_prefix.'/docs/alpha.txt');
        Storage::disk('local')->assertExists($workspace->root_prefix.'/archive/alpha-copy.txt');
        Storage::disk('local')->assertMissing($workspace->root_prefix.'/docs/alpha-copy.txt');
    }

    #[Test]
    public function it_creates_directories_and_reads_truncated_files_through_gateway_tools(): void
    {
        [$user, $workspace] = $this->createWorkspaceContext();

        Storage::disk('local')->put($workspace->root_prefix.'/docs/large.txt', str_repeat('z', 128));

        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Workspace directory and read task',
            'prompt' => 'Create dir and inspect file',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['create_workspace_directory', 'read_workspace_file'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'create_workspace_directory',
            'arguments' => [
                'workspace_id' => $workspace->id,
                'path' => 'generated/reports',
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'read_workspace_file',
            'arguments' => [
                'workspace_id' => $workspace->id,
                'path' => 'docs/large.txt',
                'max_bytes' => 12,
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.truncated', true)
            ->assertJsonPath('data.result.returned_bytes', 12)
            ->assertJsonPath('data.result.size_bytes', 128);
    }

    #[Test]
    public function it_limits_workspace_tools_to_the_task_scope(): void
    {
        [$user, $workspace, $organization, $team] = $this->createWorkspaceContextWithScope();

        $otherOrganization = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org',
        ]);

        $otherTeam = Team::create([
            'organization_id' => $otherOrganization->id,
            'methodology_id' => $team->methodology_id,
            'name' => 'Other Team',
            'slug' => 'other-team',
        ]);

        $foreignWorkspace = Workspace::create([
            'organization_id' => $otherOrganization->id,
            'team_id' => $otherTeam->id,
            'owner_user_id' => null,
            'name' => 'Foreign Workspace',
            'slug' => 'foreign-workspace',
            'scope_type' => 'team_shared',
            'root_prefix' => 'workspaces/orgs/'.$otherOrganization->id.'/teams/'.$otherTeam->id.'/shared/foreign-workspace',
            'storage_disk' => 'local',
            'status' => 'active',
        ]);

        WorkspacePermission::create([
            'workspace_id' => $foreignWorkspace->id,
            'principal_type' => 'user',
            'principal_id' => (string) $user->id,
            'can_list' => true,
            'can_read' => true,
            'can_write' => false,
            'can_delete' => false,
            'can_execute' => false,
            'can_admin' => false,
        ]);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Scoped workspace task',
            'prompt' => 'Inspect scoped workspace',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['list_workspaces', 'read_workspace_file'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'list_workspaces',
            'arguments' => [],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonCount(1, 'data.result.workspaces')
            ->assertJsonPath('data.result.workspaces.0.id', $workspace->id);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'read_workspace_file',
            'arguments' => [
                'workspace_id' => $foreignWorkspace->id,
                'path' => 'secret.txt',
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', false)
            ->assertJsonPath('data.result.error', 'Workspace not found or access denied');
    }

    #[Test]
    public function it_creates_a_private_workspace_within_the_task_scope(): void
    {
        [$user, $workspace, $organization, $team] = $this->createWorkspaceContextWithScope();

        $task = AgentTask::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Create scoped workspace task',
            'prompt' => 'Create a workspace',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['create_workspace', 'list_workspaces'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $response = $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'create_workspace',
            'arguments' => [
                'name' => 'Agent Scratchpad',
                'scope_type' => 'user_team_private',
                'metadata' => json_encode(['created_by' => 'agent']),
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.workspace.name', 'Agent Scratchpad')
            ->assertJsonPath('data.result.workspace.organization_id', $organization->id)
            ->assertJsonPath('data.result.workspace.team_id', $team->id)
            ->assertJsonPath('data.result.workspace.owner_user_id', $user->id)
            ->assertJsonPath('data.result.workspace.scope_type', 'user_team_private');

        $workspaceId = $response->json('data.result.workspace.id');

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspaceId,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'owner_user_id' => $user->id,
            'scope_type' => 'user_team_private',
            'name' => 'Agent Scratchpad',
        ]);
    }

    #[Test]
    public function it_rejects_workspace_creation_outside_the_task_scope(): void
    {
        [$user, $workspace, $organization, $team] = $this->createWorkspaceContextWithScope();

        $otherTeam = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $team->methodology_id,
            'name' => 'Other Scoped Team',
            'slug' => 'other-scoped-team',
        ]);
        $otherTeam->users()->attach($user->id);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Reject create workspace task',
            'prompt' => 'Create a workspace in a different team',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['create_workspace'],
            'next_run_at' => now(),
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'create_workspace',
            'arguments' => [
                'name' => 'Cross Team Workspace',
                'scope_type' => 'user_team_private',
                'organization_id' => $organization->id,
                'team_id' => $otherTeam->id,
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', false)
            ->assertJsonPath('data.result.error', 'Workspace validation failed.')
            ->assertJsonPath('data.result.details.team_id.0', 'Workspace creation is restricted to the current team scope.');
    }

    private function createWorkspaceContext(bool $grantWrite = true): array
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Sandbox Org',
            'slug' => 'sandbox-org',
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Sandbox Team',
            'slug' => 'sandbox-team',
        ]);

        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'employee']);
        $team->users()->attach($user->id);

        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'owner_user_id' => null,
            'name' => 'Readable Workspace',
            'slug' => 'readable-workspace',
            'scope_type' => 'user_team_private',
            'root_prefix' => 'workspaces/orgs/'.$organization->id.'/teams/'.$team->id.'/users/readable-workspace',
            'storage_disk' => 'local',
            'status' => 'active',
        ]);

        WorkspacePermission::create([
            'workspace_id' => $workspace->id,
            'principal_type' => 'user',
            'principal_id' => (string) $user->id,
            'can_list' => true,
            'can_read' => true,
            'can_write' => $grantWrite,
            'can_delete' => $grantWrite,
            'can_execute' => $grantWrite,
            'can_admin' => false,
        ]);

        return [$user, $workspace];
    }

    private function createWorkspaceContextWithScope(bool $grantWrite = true): array
    {
        [$user, $workspace] = $this->createWorkspaceContext($grantWrite);

        return [$user, $workspace, $workspace->organization()->firstOrFail(), $workspace->team()->firstOrFail()];
    }
}
