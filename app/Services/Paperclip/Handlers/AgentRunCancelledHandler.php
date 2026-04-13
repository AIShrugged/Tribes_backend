<?php

namespace App\Services\Paperclip\Handlers;

use App\Enums\AgentTaskRunStatus;
use App\Models\AgentTaskRun;
use App\Services\IssueAgentFlowProgressService;
use App\Services\Paperclip\PaperclipEventHandlerInterface;
use App\Services\Paperclip\PaperclipPayloadInterface;
use App\Services\Paperclip\Payloads\AgentRunPayload;
use Illuminate\Support\Facades\Log;

class AgentRunCancelledHandler implements PaperclipEventHandlerInterface
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
            Log::warning('Paperclip webhook agent.run.cancelled: run not found', [
                'paperclip_issue_id' => $payload->issueId,
            ]);

            return;
        }

        if ($run->status === AgentTaskRunStatus::COMPLETED || $run->status === AgentTaskRunStatus::FAILED) {
            return;
        }

        $task = $run->task;

        if (! $task) {
            Log::warning('Paperclip webhook agent.run.cancelled: task not found for run', [
                'agent_task_run_id'  => $run->id,
                'paperclip_issue_id' => $payload->issueId,
            ]);

            return;
        }

        $errorMessage = $payload->errorMessage ?? 'Paperclip agent run was cancelled.';

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

        Log::info('Paperclip agent run cancelled', [
            'agent_task_id'      => $task->id,
            'agent_task_run_id'  => $run->id,
            'paperclip_issue_id' => $payload->issueId,
            'reason'             => $errorMessage,
        ]);

        try {
            $this->flowProgressService->handleTaskFailed($task, $run);
        } catch (\Throwable $e) {
            Log::warning('Paperclip webhook: flow progress failed after task cancellation', [
                'agent_task_id'     => $task->id,
                'agent_task_run_id' => $run->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }
}
