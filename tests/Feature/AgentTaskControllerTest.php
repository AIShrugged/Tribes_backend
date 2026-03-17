<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentTaskControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_lists_updates_and_deletes_agent_tasks(): void
    {
        $user = User::factory()->create();
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
            'schedule_type' => 'interval',
        ])->assertStatus(422)
            ->assertJsonPath('meta.error_code', 'AGENT_TASK_INTERVAL_REQUIRED');
    }

    #[Test]
    public function it_only_exposes_tasks_belonging_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $task = AgentTask::create([
            'user_id' => $owner->id,
            'name' => 'Owner task',
            'prompt' => 'Owner only.',
            'schedule_type' => 'one_off',
            'next_run_at' => now(),
        ]);

        $this->actingAs($otherUser)
            ->getJson("/api/v1/agent-tasks/{$task->id}")
            ->assertStatus(404);
    }
}
