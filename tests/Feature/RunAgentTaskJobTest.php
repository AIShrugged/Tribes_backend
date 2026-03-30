<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTaskJob;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunAgentTaskJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_uses_the_dedicated_agent_tasks_queue_and_timeout(): void
    {
        $job = new RunAgentTaskJob(1, 2, 3);

        $this->assertSame('agent-tasks', $job->queue);
        $this->assertSame(1800, $job->timeout);
    }

    #[Test]
    public function it_completes_one_off_agent_task_and_disables_it(): void
    {
        $user = User::factory()->create();
        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'One-off task',
            'prompt' => 'Write a short update.',
            'schedule_type' => 'one_off',
            'interval_seconds' => null,
            'next_run_at' => now()->subMinute(),
            'enabled' => true,
            'locked_at' => now(),
            'agent_task_type' => 'background',
            'output_mode' => 'plain',
            'max_attempts' => 2,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'queued',
            'scheduled_for' => now()->subMinute(),
        ]);

        $job = new RunAgentTaskJob($task->id, $run->id, 2);
        $this->app->call([$job, 'handle']);

        $task = $task->fresh();
        $run = $run->fresh();

        $this->assertSame('completed', $run->status->value);
        $this->assertSame('Mocked testing response', $run->output);
        $this->assertFalse($task->enabled);
        $this->assertNull($task->next_run_at);
        $this->assertNull($task->locked_at);
        $this->assertNotNull($task->last_completed_at);
    }

    #[Test]
    public function it_advances_next_run_for_interval_tasks(): void
    {
        Carbon::setTestNow('2026-03-17 12:00:00');

        $user = User::factory()->create();
        $task = AgentTask::create([
            'user_id' => $user->id,
            'name' => 'Recurring task',
            'prompt' => 'Generate a status check.',
            'schedule_type' => 'interval',
            'interval_seconds' => 600,
            'next_run_at' => now(),
            'enabled' => true,
            'locked_at' => now(),
            'agent_task_type' => 'background',
            'output_mode' => 'plain',
            'max_attempts' => 1,
        ]);

        $run = AgentTaskRun::create([
            'agent_task_id' => $task->id,
            'status' => 'queued',
            'scheduled_for' => now(),
        ]);

        $job = new RunAgentTaskJob($task->id, $run->id, 1);
        $this->app->call([$job, 'handle']);

        $task = $task->fresh();

        $this->assertTrue($task->enabled);
        $this->assertSame('2026-03-17 12:10:00', $task->next_run_at?->format('Y-m-d H:i:s'));
        $this->assertNull($task->locked_at);

        Carbon::setTestNow();
    }
}
