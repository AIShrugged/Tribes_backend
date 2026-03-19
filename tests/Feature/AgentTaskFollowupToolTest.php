<?php

namespace Tests\Feature;

use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\AgentTaskRunTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentTaskFollowupToolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sandbox_run_can_create_small_followup_agent_task(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Parent sandbox task',
            'prompt' => 'Inspect workspace and split work.',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'agent_task_type' => 'background',
            'output_mode' => 'plain',
            'allowed_tools' => ['create_followup_agent_task', 'list_workspaces'],
            'allowed_outbound_hosts' => ['api.github.com'],
            'input_payload' => ['provider' => 'github', 'repo' => 'acme/api'],
            'next_run_at' => now(),
            'enabled' => true,
            'max_attempts' => 2,
            'metadata' => ['max_iterations' => 8],
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $token = $this->app->make(AgentTaskRunTokenService::class)->issue($run);

        $response = $this->postJson("/api/v1/internal/agent-task-runs/{$run->id}/tool-calls", [
            'tool_name' => 'create_followup_agent_task',
            'arguments' => [
                'name' => 'Create PR for scanned changes',
                'prompt' => 'Prepare a PR with the generated patch and summarize the changes.',
                'context_summary' => 'The parent task already scanned the repository and prepared the patch plan.',
                'delay_seconds' => 120,
                'allowed_tools' => ['list_workspaces'],
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.result.success', true);

        $followupTaskId = $response->json('data.result.agent_task.id');
        $followup = AgentTask::findOrFail($followupTaskId);

        $this->assertSame($task->id, $followup->parent_agent_task_id);
        $this->assertSame($organization->id, $followup->organization_id);
        $this->assertSame($team->id, $followup->team_id);
        $this->assertSame($run->id, $followup->origin_agent_task_run_id);
        $this->assertSame(1, $followup->followup_depth);
        $this->assertSame('one_off', $followup->schedule_type->value);
        $this->assertTrue($followup->enabled);
        $this->assertSame($task->agent_task_type, $followup->agent_task_type);
        $this->assertSame($task->output_mode, $followup->output_mode);
        $this->assertSame($task->execution_mode->value, $followup->execution_mode->value);
        $this->assertSame(['list_workspaces'], $followup->allowed_tools);
        $this->assertSame($task->allowed_outbound_hosts, $followup->allowed_outbound_hosts);
        $this->assertSame($task->input_payload, $followup->input_payload);
        $this->assertStringContainsString('## Follow-up Context', $followup->prompt);
        $this->assertTrue($followup->next_run_at->gt(now()));

        $run->refresh();
        $this->assertSame(
            'create_followup_agent_task',
            data_get($run->metadata, 'tool_calls.0.tool_name')
        );
        $this->assertSame(
            $followup->id,
            data_get($run->metadata, 'tool_calls.0.created_agent_task_id')
        );
    }

    #[Test]
    public function followup_task_cannot_request_tools_outside_parent_allowlist(): void
    {
        $user = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($user);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'name' => 'Parent sandbox task',
            'prompt' => 'Inspect workspace and split work.',
            'schedule_type' => 'one_off',
            'execution_mode' => 'isolated',
            'allowed_tools' => ['create_followup_agent_task', 'list_workspaces'],
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
            'tool_name' => 'create_followup_agent_task',
            'arguments' => [
                'name' => 'Illegal follow-up',
                'prompt' => 'Try to expand privileges.',
                'allowed_tools' => ['execute_sql_query', 'list_workspaces'],
            ],
        ], [
            'X-Sandbox-Run-Token' => $token,
        ])->assertStatus(200)
            ->assertJsonPath('data.result.success', false)
            ->assertJsonPath('data.result.error', 'Follow-up task requested tools outside the parent allowlist.');
    }

    private function createTenantContextFor(User $user): array
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

        $organization->users()->attach($user->id, ['role' => 'employee']);
        $team->users()->attach($user->id);

        return [$organization, $team];
    }
}
