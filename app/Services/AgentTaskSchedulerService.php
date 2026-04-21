<?php

namespace App\Services;

use App\Enums\AgentTaskRunStatus;
use App\Jobs\RunAgentTaskJob;
use App\Models\AgentTask;
use Illuminate\Support\Facades\DB;

class AgentTaskSchedulerService
{
    public function dispatchTaskNow(AgentTask $task, bool $force = false)
    {
        return DB::transaction(function () use ($task, $force) {
            $task = AgentTask::query()->lockForUpdate()->find($task->id);

            if (! $task) {
                return null;
            }

            if (! $force && ! $task->enabled) {
                return null;
            }

            $activeRunExists = $task->runs()
                ->whereIn('status', [AgentTaskRunStatus::QUEUED->value, AgentTaskRunStatus::PROCESSING->value])
                ->exists();

            if ($activeRunExists) {
                return null;
            }

            if ($task->locked_at && $task->locked_at->gt(now()->subSeconds((int) config('agent.agent_tasks.lock_ttl_seconds', 600)))) {
                if (! $force) {
                    return null;
                }

                $task->update([
                    'locked_at' => null,
                ]);
            }

            $task->update([
                'locked_at' => now(),
                'enabled'   => true,
                'last_error' => null,
            ]);

            $run = $task->runs()->create([
                'status' => AgentTaskRunStatus::QUEUED->value,
                'attempt' => 0,
                'scheduled_for' => now(),
            ]);

            RunAgentTaskJob::dispatch(
                $task->id,
                $run->id,
                max(1, (int) $task->max_attempts),
            );

            return $run;
        });
    }

    public function dispatchDueTasks(int $limit = 50): int
    {
        $taskIds = AgentTask::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', now()->subSeconds((int) config('agent.agent_tasks.lock_ttl_seconds', 600)));
            })
            ->orderBy('next_run_at')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;

        foreach ($taskIds as $taskId) {
            $claimed = DB::transaction(function () use ($taskId) {
                $task = AgentTask::query()->lockForUpdate()->find($taskId);

                if (! $task || ! $task->enabled || ! $task->next_run_at || $task->next_run_at->isFuture()) {
                    return null;
                }

                if ($task->locked_at && $task->locked_at->gt(now()->subSeconds((int) config('agent.agent_tasks.lock_ttl_seconds', 600)))) {
                    return null;
                }

                $task->update([
                    'locked_at' => now(),
                    'last_error' => null,
                ]);

                $run = $task->runs()->create([
                    'status' => AgentTaskRunStatus::QUEUED->value,
                    'attempt' => 0,
                    'scheduled_for' => $task->next_run_at,
                ]);

                return [
                    'task_id' => $task->id,
                    'run_id' => $run->id,
                    'max_attempts' => max(1, (int) $task->max_attempts),
                ];
            });

            if (! $claimed) {
                continue;
            }

            RunAgentTaskJob::dispatch(
                $claimed['task_id'],
                $claimed['run_id'],
                $claimed['max_attempts'],
            );

            $dispatched++;
        }

        return $dispatched;
    }
}
