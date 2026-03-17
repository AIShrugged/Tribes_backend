<?php

namespace Tests\Feature;

use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\User;
use App\Services\AgentTaskRunTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SandboxToolGatewayControllerTest extends TestCase
{
    use RefreshDatabase;

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
}
