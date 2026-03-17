<?php

namespace Tests\Feature;

use App\Models\AgentMemory;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentMemoryControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_and_shows_memories_accessible_through_the_users_tasks(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $profile = AgentProfile::create([
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
        ]);

        AgentTask::create([
            'user_id' => $user->id,
            'agent_profile_id' => $profile->id,
            'name' => 'Scan repo',
            'prompt' => 'Scan repo',
            'schedule_type' => 'one_off',
            'next_run_at' => now(),
            'input_payload' => [
                'provider' => 'github',
                'owner' => 'acme',
                'repo' => 'api',
            ],
        ]);

        $foreignProfile = AgentProfile::create([
            'key' => 'other-profile',
            'name' => 'Other Profile',
        ]);

        AgentTask::create([
            'user_id' => $otherUser->id,
            'agent_profile_id' => $foreignProfile->id,
            'name' => 'Foreign scan',
            'prompt' => 'Foreign scan',
            'schedule_type' => 'one_off',
            'next_run_at' => now(),
        ]);

        $visibleMemory = AgentMemory::create([
            'agent_profile_id' => $profile->id,
            'scope_type' => 'repository',
            'scope_key' => 'github:acme/api',
            'kind' => 'architecture_fact',
            'content' => 'Repository uses layered architecture.',
            'priority' => 90,
            'active' => true,
        ]);

        AgentMemory::create([
            'agent_profile_id' => $foreignProfile->id,
            'scope_type' => 'profile',
            'kind' => 'instruction',
            'content' => 'Foreign memory.',
            'priority' => 50,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/agent-memories')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $visibleMemory->id)
            ->assertJsonMissing(['content' => 'Foreign memory.']);

        $this->actingAs($user)
            ->getJson("/api/v1/agent-memories/{$visibleMemory->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.content', 'Repository uses layered architecture.');
    }

    #[Test]
    public function it_lists_profile_and_task_relevant_memories(): void
    {
        $user = User::factory()->create();
        $profile = AgentProfile::create([
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
        ]);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'agent_profile_id' => $profile->id,
            'name' => 'Scan repo',
            'prompt' => 'Scan repo',
            'schedule_type' => 'one_off',
            'next_run_at' => now(),
            'input_payload' => [
                'provider' => 'github',
                'owner' => 'acme',
                'repo' => 'api',
            ],
        ]);

        AgentMemory::create([
            'agent_profile_id' => $profile->id,
            'scope_type' => 'profile',
            'kind' => 'instruction',
            'content' => 'Always check tests first.',
            'priority' => 100,
            'active' => true,
        ]);

        AgentMemory::create([
            'agent_profile_id' => $profile->id,
            'scope_type' => 'repository',
            'scope_key' => 'github:acme/api',
            'kind' => 'fact',
            'content' => 'Uses PHPUnit.',
            'priority' => 80,
            'active' => true,
        ]);

        AgentMemory::create([
            'agent_profile_id' => $profile->id,
            'scope_type' => 'task',
            'scope_key' => (string) $task->id,
            'kind' => 'fact',
            'content' => 'Task-local note.',
            'priority' => 70,
            'active' => true,
        ]);

        AgentMemory::create([
            'agent_profile_id' => $profile->id,
            'scope_type' => 'repository',
            'scope_key' => 'github:acme/other',
            'kind' => 'fact',
            'content' => 'Should not be included.',
            'priority' => 10,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/agent-profiles/{$profile->id}/memories")
            ->assertStatus(200)
            ->assertJsonCount(4, 'data');

        $this->actingAs($user)
            ->getJson("/api/v1/agent-tasks/{$task->id}/memories")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['content' => 'Always check tests first.'])
            ->assertJsonFragment(['content' => 'Uses PHPUnit.'])
            ->assertJsonFragment(['content' => 'Task-local note.'])
            ->assertJsonMissing(['content' => 'Should not be included.']);
    }
}
