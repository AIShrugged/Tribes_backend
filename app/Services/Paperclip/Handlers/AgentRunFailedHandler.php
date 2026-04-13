<?php

namespace App\Services\Paperclip\Handlers;

use App\Enums\AgentTaskRunStatus;
use App\Models\AgentTaskRun;
use App\Services\IssueAgentFlowProgressService;
use App\Services\Paperclip\PaperclipEventHandlerInterface;
use App\Services\Paperclip\PaperclipPayloadInterface;
use App\Services\Paperclip\Payloads\AgentRunPayload;
use Illuminate\Support\Facades\Log;

class AgentRunFailedHandler implements PaperclipEventHandlerInterface
{
    public function __construct(
        private readonly IssueAgentFlowProgressService $flowProgressService,
    ) {}

    public function handle(AgentRunPayload|PaperclipPayloadInterface $payload): void
    {
        $run = AgentTaskRun::where('paperclip_issue_id', $payload->issueId)
            ->with('task')
            ->first();

        if (! $run) {
            Log::warning('Paperclip webhook agent.run.failed: run not found', [
                'paperclip_issue_id' => $payload->issueId,
            ]);

            return;
        }

        if ($run->status === AgentTaskRunStatus::COMPLETED || $run->status === AgentTaskRunStatus::FAILED) {
            return;
        }

        $task = $run->task;

        if (! $task) {
            Log::warning('Paperclip webhook agent.run.failed: task not found for run', [
                'agent_task_run_id'  => $run->id,
                'paperclip_issue_id' => $payload->issueId,
            ]);

            return;
        }

        $errorMessage = $payload->errorMessage ?? 'Paperclip agent run failed.';

        $run->update([
            'status'        => AgentTaskRunStatus::FAILED->value,
            'error_message' => $errorMessage,
            'finished_at'   => now(),
        ]);

        $task->update([
            'last_failed_at' => now(),
            'last_error'     => $errorMessage,
            'locked_at'      => null,
            'next_run_at'    => $task->isInterval()
                ? $task->nextRunFrom($run->scheduled_for ?? now())
                : $task->next_run_at,
        ]);

        Log::info('Paperclip agent run failed', [
            'agent_task_id'      => $task->id,
            'agent_task_run_id'  => $run->id,
            'paperclip_issue_id' => $payload->issueId,
            'error'              => $errorMessage,
        ]);

        try {
            $this->flowProgressService->handleTaskFailed($task, $run);
        } catch (\Throwable $e) {
            Log::warning('Paperclip webhook: flow progress failed after task failure', [
                'agent_task_id'     => $task->id,
                'agent_task_run_id' => $run->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }
}
