<?php

namespace App\Jobs;

use App\Enums\AgentTaskRunStatus;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Services\IssueAgentFlowProgressService;
use App\Services\PaperclipApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CheckPaperclipIssueStatusJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $agentTaskRunId,
        public readonly int $pollStep,
        public readonly int $elapsedSeconds,
    ) {
        $this->onQueue('agent-tasks');
    }

    public function handle(
        PaperclipApiClient $client,
        IssueAgentFlowProgressService $flowProgressService,
    ): void {
        $run = AgentTaskRun::with('task')->find($this->agentTaskRunId);

        if (! $run) {
            return;
        }

        if ($run->status === AgentTaskRunStatus::COMPLETED || $run->status === AgentTaskRunStatus::FAILED) {
            return;
        }

        $task    = $run->task;
        $issueId = $run->paperclip_issue_id;

        if (! $task || ! $issueId) {
            $this->failRun($run, $task, 'Paperclip issue ID missing on run.', $flowProgressService);

            return;
        }

        $issue  = $client->getIssue($issueId);
        $status = $issue['status'] ?? null;

        Log::debug('Paperclip status check', [
            'agent_task_run_id'  => $run->id,
            'paperclip_issue_id' => $issueId,
            'status'             => $status,
            'elapsed'            => $this->elapsedSeconds,
        ]);


        if ($status === 'done') {
            $output = $this->extractOutput($issue, $issueId, $client);
            $this->completeRun($run, $task, $output, $flowProgressService);

            return;
        }

        if ($status === 'cancelled') {
            $this->failRun($run, $task, "Paperclip issue {$issueId} was cancelled.", $flowProgressService);

            return;
        }

        $maxSeconds = config('paperclip.polling.max_seconds', 1800);

        if ($this->elapsedSeconds >= $maxSeconds) {
            $this->failRun(
                $run,
                $task,
                "Paperclip polling timeout after {$this->elapsedSeconds}s for issue {$issueId}.",
                $flowProgressService,
            );

            return;
        }

        $intervals    = config('paperclip.polling.intervals', [1, 2, 5, 10, 10, 10]);
        $nextInterval = $intervals[min($this->pollStep, count($intervals) - 1)];

        static::dispatch($this->agentTaskRunId, $this->pollStep + 1, $this->elapsedSeconds + $nextInterval)
            ->delay(now()->addSeconds($nextInterval));
    }

    private function extractOutput(array $issue, string $issueId, PaperclipApiClient $client): string
    {
        if (! empty($issue['planDocument'])) {
            return $issue['planDocument'];
        }

        $comments = $client->getIssueComments($issueId);

        if (! empty($comments)) {
            $last = end($comments);

            return $last['body'] ?? '';
        }

        return '';
    }

    private function completeRun(
        AgentTaskRun $run,
        AgentTask $task,
        string $output,
        IssueAgentFlowProgressService $flowProgressService,
    ): void {
        $run->update([
            'status'        => AgentTaskRunStatus::COMPLETED->value,
            'output'        => $output,
            'finished_at'   => now(),
            'error_message' => null,
        ]);

        $nextRunAt = $task->nextRunFrom($run->scheduled_for ?? now());

        $task->update([
            'enabled'           => $task->isOneOff() ? false : $task->enabled,
            'next_run_at'       => $nextRunAt,
            'last_completed_at' => now(),
            'last_failed_at'    => null,
            'last_error'        => null,
            'locked_at'         => null,
        ]);

        Log::info('Paperclip issue completed', [
            'agent_task_id'      => $task->id,
            'agent_task_run_id'  => $run->id,
            'paperclip_issue_id' => $run->paperclip_issue_id,
        ]);

        try {
            $flowProgressService->handleTaskCompleted($task, $run);
        } catch (\Throwable $e) {
            Log::warning('Paperclip: flow progress failed after task completion', [
                'agent_task_id'     => $task->id,
                'agent_task_run_id' => $run->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    private function failRun(
        AgentTaskRun $run,
        ?AgentTask $task,
        string $errorMessage,
        IssueAgentFlowProgressService $flowProgressService,
    ): void {
        $run->update([
            'status'        => AgentTaskRunStatus::FAILED->value,
            'error_message' => $errorMessage,
            'finished_at'   => now(),
        ]);

        if (! $task) {
            return;
        }

        $task->update([
            'last_failed_at' => now(),
            'last_error'     => $errorMessage,
            'locked_at'      => null,
            'next_run_at'    => $task->isInterval()
                ? $task->nextRunFrom($run->scheduled_for ?? now())
                : $task->next_run_at,
        ]);

        Log::info('Paperclip issue failed', [
            'agent_task_id'      => $task->id,
            'agent_task_run_id'  => $run->id,
            'paperclip_issue_id' => $run->paperclip_issue_id,
            'error'              => $errorMessage,
        ]);

        try {
            $flowProgressService->handleTaskFailed($task, $run);
        } catch (\Throwable $e) {
            Log::warning('Paperclip: flow progress failed after task failure', [
                'agent_task_id'     => $task->id,
                'agent_task_run_id' => $run->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }
}
