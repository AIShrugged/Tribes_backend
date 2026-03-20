<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Jobs\RunAgentTaskJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentTaskControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_lists_updates_and_deletes_agent_tasks(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);
        $profile = AgentProfile::create([
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
            'task_payload_schema' => [
                'type' => 'object',
                'required' => ['provider', 'owner', 'repo'],
                'properties' => [
                    'provider' => ['type' => 'string'],
                    'owner' => ['type' => 'string'],
                    'repo' => ['type' => 'string'],
                ],
            ],
            'execution_mode' => 'isolated',
            'allowed_tools' => ['search_memory'],
            'allowed_outbound_hosts' => ['api.github.com'],
        ]);

        $createResponse = $this->actingAs($user)->postJson('/api/v1/agent-tasks', [
            'name' => 'Scan acme/api',
            'prompt' => 'Inspect repository and update memory.',
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'agent_profile_id' => $profile->id,
            'schedule_type' => 'interval',
            'interval_seconds' => 3600,
            'input_payload' => [
                'provider' => 'github',
                'owner' => 'acme',
                'repo' => 'api',
            ],
            'allowed_outbound_hosts' => ['github.com', 'api.github.com'],
            'metadata' => [
                'max_iterations' => 8,
            ],
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Scan acme/api')
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.team_id', $team->id)
            ->assertJsonPath('data.schedule_type', 'interval')
            ->assertJsonPath('data.effective_execution_mode', 'isolated')
            ->assertJsonPath('data.input_payload.owner', 'acme');

        $taskId = $createResponse->json('data.id');

        $this->actingAs($user)
            ->getJson('/api/v1/agent-tasks')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $taskId);

        $this->actingAs($user)
            ->patchJson("/api/v1/agent-tasks/{$taskId}", [
                'schedule_type' => 'one_off',
                'interval_seconds' => null,
                'enabled' => false,
            ])->assertStatus(200)
            ->assertJsonPath('data.schedule_type', 'one_off')
            ->assertJsonPath('data.interval_seconds', null)
            ->assertJsonPath('data.enabled', false);

        $this->actingAs($user)
            ->getJson("/api/v1/agent-tasks/{$taskId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $taskId);

        $this->actingAs($user)
            ->deleteJson("/api/v1/agent-tasks/{$taskId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('agent_tasks', [
            'id' => $taskId,
        ]);
    }

    #[Test]
    public function it_validates_task_payload_and_interval_requirements(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);
        $profile = AgentProfile::create([
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
            'task_payload_schema' => [
                'type' => 'object',
                'required' => ['provider', 'owner', 'repo'],
                'properties' => [
                    'provider' => ['type' => 'string'],
                    'owner' => ['type' => 'string'],
                    'repo' => ['type' => 'string'],
                ],
            ],
        ]);

        $this->actingAs($user)->postJson('/api/v1/agent-tasks', [
            'name' => 'Broken task',
            'prompt' => 'Broken payload.',
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'agent_profile_id' => $profile->id,
            'schedule_type' => 'one_off',
            'input_payload' => [
                'provider' => 'github',
                'owner' => 'acme',
            ],
        ])->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'INVALID_JSON_PAYLOAD');

        $this->actingAs($user)->postJson('/api/v1/agent-tasks', [
            'name' => 'Broken interval',
            'prompt' => 'Missing interval.',
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'schedule_type' => 'interval',
        ])->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'AGENT_TASK_INTERVAL_REQUIRED');
    }

    #[Test]
    public function it_rejects_task_creation_for_foreign_team_scope(): void
    {
        $user = User::factory()->create();
        [$organization] = $this->createTenantContextFor($user);

        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $otherOrganization = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org',
        ]);

        $otherTeam = Team::create([
            'organization_id' => $otherOrganization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Other Team',
            'slug' => 'other-team',
        ]);

        $this->actingAs($user)->postJson('/api/v1/agent-tasks', [
            'name' => 'Foreign scope task',
            'prompt' => 'Should fail.',
            'organization_id' => $organization->id,
            'team_id' => $otherTeam->id,
            'schedule_type' => 'one_off',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['team_id']);
    }

    #[Test]
    public function it_only_exposes_tasks_belonging_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        [$organization] = $this->createTenantContextFor($owner);
        $organization->users()->attach($otherUser->id, ['role' => 'employee']);

        $task = AgentTask::create([
            'user_id' => $owner->id,
            'organization_id' => $organization->id,
            'name' => 'Owner task',
            'prompt' => 'Owner only.',
            'schedule_type' => 'one_off',
            'next_run_at' => now(),
        ]);

        $this->actingAs($otherUser)
            ->getJson("/api/v1/agent-tasks/{$task->id}")
            ->assertStatus(403);
    }

    #[Test]
    public function it_lists_task_runs_shows_run_details_and_dispatches_now(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Run task',
            'prompt' => 'Inspect workspace.',
            'schedule_type' => 'one_off',
            'enabled' => true,
            'max_attempts' => 3,
            'next_run_at' => now(),
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'completed',
            'attempt' => 1,
            'scheduled_for' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'output' => 'done',
            'metadata' => [
                'sandbox_result' => ['plan' => ['step 1']],
                'tool_calls' => [['tool_name' => 'list_workspaces']],
            ],
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/agent-tasks/{$task->id}/runs")
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $run->id)
            ->assertJsonPath('data.0.output', 'done');

        $this->actingAs($user)
            ->getJson("/api/v1/agent-tasks/{$task->id}/runs/{$run->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonPath('data.metadata.sandbox_result.plan.0', 'step 1');

        $dispatchResponse = $this->actingAs($user)
            ->postJson("/api/v1/agent-tasks/{$task->id}/dispatch")
            ->assertStatus(201)
            ->assertJsonPath('data.agent_task_id', $task->id)
            ->assertJsonPath('data.status', 'queued');

        $dispatchedRunId = $dispatchResponse->json('data.id');

        $this->assertDatabaseHas('agent_task_runs', [
            'id' => $dispatchedRunId,
            'agent_task_id' => $task->id,
            'status' => 'queued',
        ]);

        Queue::assertPushed(RunAgentTaskJob::class);
    }

    #[Test]
    public function it_exposes_task_meta_and_tool_catalog(): void
    {
        $user = User::factory()->create();
        $this->createTenantContextFor($user);

        $this->actingAs($user)
            ->getJson('/api/v1/agent-tasks/meta')
            ->assertStatus(200)
            ->assertJsonPath('data.schedule_types.0', 'one_off')
            ->assertJsonPath('data.schedule_types.1', 'interval')
            ->assertJsonPath('data.execution_modes.0', 'inline')
            ->assertJsonPath('data.execution_modes.1', 'isolated')
            ->assertJsonPath('data.task_types.3', 'background');

        $this->actingAs($user)
            ->getJson('/api/v1/agent-tools')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'create_workspace'])
            ->assertJsonFragment(['name' => 'list_workspaces']);
    }

    #[Test]
    public function non_manager_cannot_manage_agent_tasks_or_view_runs(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user, role: 'employee');

        $task = AgentTask::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Employee task',
            'prompt' => 'Should not be manageable.',
            'schedule_type' => 'one_off',
            'enabled' => true,
            'next_run_at' => now(),
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'queued',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/agent-tasks')
            ->assertStatus(403);

        $this->actingAs($user)
            ->postJson('/api/v1/agent-tasks', [
                'name' => 'Blocked task',
                'prompt' => 'Blocked',
                'organization_id' => $organization->id,
                'team_id' => $team->id,
                'schedule_type' => 'one_off',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id']);

        $this->actingAs($user)
            ->getJson("/api/v1/agent-tasks/{$task->id}/runs")
            ->assertStatus(403);

        $this->actingAs($user)
            ->getJson("/api/v1/agent-tasks/{$task->id}/runs/{$run->id}")
            ->assertStatus(403);

        $this->actingAs($user)
            ->getJson('/api/v1/agent-tasks/meta')
            ->assertStatus(403);

        $this->actingAs($user)
            ->getJson('/api/v1/agent-tools')
            ->assertStatus(403);
    }

    private function createTenantContextFor(User $user, string $role = 'manager'): array
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Platform',
            'slug' => 'platform',
        ]);

        $organization->users()->attach($user->id, ['role' => $role]);
        $team->users()->attach($user->id);

        return [$organization, $team];
    }
}
