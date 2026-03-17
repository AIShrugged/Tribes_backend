<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\User;
use App\Services\AgentMemoryIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentMemoryIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_ingests_repository_memory_candidates_and_deduplicates_them(): void
    {
        $user = User::factory()->create();
        $profile = AgentProfile::create([
            'key' => 'github-reviewer',
            'name' => 'GitHub Reviewer',
            'execution_mode' => 'isolated',
        ]);

        $task = AgentTask::create([
            'user_id' => $user->id,
            'agent_profile_id' => $profile->id,
            'name' => 'Scan repo',
            'prompt' => 'Scan repository state',
            'schedule_type' => 'one_off',
            'next_run_at' => now(),
            'input_payload' => [
                'provider' => 'github',
                'owner' => 'acme',
                'repo' => 'api',
            ],
            'enabled' => true,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $service = $this->app->make(AgentMemoryIngestionService::class);

        $first = $service->ingest($task, $run, [[
            'kind' => 'architecture_fact',
            'content' => 'Repository uses layered architecture: app/services/repositories.',
            'priority' => 90,
        ]]);

        $second = $service->ingest($task, $run, [[
            'kind' => 'architecture_fact',
            'content' => 'Repository uses layered architecture: app/services/repositories.',
            'priority' => 90,
        ]]);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertDatabaseCount('agent_memories', 1);
        $this->assertDatabaseHas('agent_memories', [
            'agent_profile_id' => $profile->id,
            'scope_type' => 'repository',
            'scope_key' => 'github:acme/api',
            'kind' => 'architecture_fact',
        ]);
    }
}
