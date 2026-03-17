<?php

namespace App\Jobs;

use App\Enums\AgentTaskRunStatus;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Services\InlineAgentTaskExecutor;
use App\Services\IsolatedAgentTaskExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

class RunAgentTaskJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public int $agentTaskId,
        public int $agentTaskRunId,
        public int $maxAttempts,
    ) {}

    public function tries(): int
    {
        return max(1, $this->maxAttempts);
    }

    public function backoff(): array
    {
        return array_values((array) config('agent.agent_tasks.backoff_seconds', [30, 120]));
    }

    public function handle(
        InlineAgentTaskExecutor $inlineExecutor,
        IsolatedAgentTaskExecutor $isolatedExecutor,
    ): void
    {
        $task = AgentTask::find($this->agentTaskId);
        $run = AgentTaskRun::find($this->agentTaskRunId);

        if (! $task || ! $run) {
            return;
        }

        if (! $task->enabled && $task->isOneOff()) {
            return;
        }

        $attempt = $this->attempts();

        $run->update([
            'status' => AgentTaskRunStatus::PROCESSING->value,
            'attempt' => $attempt,
            'started_at' => $run->started_at ?? now(),
            'error_message' => null,
        ]);

        $task->update([
            'last_run_at' => now(),
            'last_error' => null,
        ]);

        try {
            $response = $task->isIsolated()
                ? $isolatedExecutor->execute($task, $run)
                : $inlineExecutor->execute($task, $run);

            $run->update([
                'status' => AgentTaskRunStatus::COMPLETED->value,
                'output' => $response,
                'finished_at' => now(),
                'error_message' => null,
            ]);

            $nextRunAt = $task->nextRunFrom($run->scheduled_for ?? now());

            $task->update([
                'enabled' => $task->isOneOff() ? false : $task->enabled,
                'next_run_at' => $nextRunAt,
                'last_completed_at' => now(),
                'last_failed_at' => null,
                'last_error' => null,
                'locked_at' => null,
            ]);
        } catch (\Throwable $e) {
            $terminal = $attempt >= $this->tries();

            $run->update([
                'status' => $terminal ? AgentTaskRunStatus::FAILED->value : AgentTaskRunStatus::QUEUED->value,
                'attempt' => $attempt,
                'error_message' => $e->getMessage(),
                'finished_at' => $terminal ? now() : null,
            ]);

            if ($terminal) {
                $task->update([
                    'enabled' => $task->isOneOff() ? false : $task->enabled,
                    'next_run_at' => $task->isInterval() ? $task->nextRunFrom($run->scheduled_for ?? now()) : null,
                    'last_failed_at' => now(),
                    'last_error' => $e->getMessage(),
                    'locked_at' => null,
                ]);
            }

            throw $e;
        }
    }
}
