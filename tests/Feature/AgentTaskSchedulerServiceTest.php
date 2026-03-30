<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTaskJob;
use App\Models\AgentTask;
use App\Models\User;
use App\Services\AgentTaskSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentTaskSchedulerServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_dispatches_due_agent_tasks_and_creates_queued_runs(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Daily summary',
            'prompt' => 'Summarize my current priorities.',
            'schedule_type' => 'interval',
            'interval_seconds' => 3600,
            'next_run_at' => now()->subMinute(),
            'enabled' => true,
            'max_attempts' => 2,
        ]);

        $dispatched = $this->app->make(AgentTaskSchedulerService::class)->dispatchDueTasks();

        $this->assertSame(1, $dispatched);
        $this->assertDatabaseHas('agent_task_runs', [
            'agent_task_id' => $task->id,
            'status' => 'queued',
            'attempt' => 0,
        ]);
        $this->assertNotNull($task->fresh()->locked_at);

        Queue::assertPushed(
            RunAgentTaskJob::class,
            fn (RunAgentTaskJob $job) => $job->agentTaskId === $task->id && $job->queue === 'agent-tasks'
        );
    }

    #[Test]
    public function it_does_not_dispatch_locked_tasks_twice(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Locked task',
            'prompt' => 'Do work.',
            'schedule_type' => 'interval',
            'interval_seconds' => 300,
            'next_run_at' => now()->subMinute(),
            'enabled' => true,
            'locked_at' => now(),
        ]);

        $dispatched = $this->app->make(AgentTaskSchedulerService::class)->dispatchDueTasks();

        $this->assertSame(0, $dispatched);
        Queue::assertNothingPushed();
    }
}
