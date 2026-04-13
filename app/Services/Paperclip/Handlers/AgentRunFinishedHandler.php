<?php

namespace App\Services\Paperclip\Handlers;

use App\Enums\AgentTaskRunStatus;
use App\Models\AgentTaskRun;
use App\Services\IssueAgentFlowProgressService;
use App\Services\Paperclip\PaperclipEventHandlerInterface;
use App\Services\Paperclip\PaperclipPayloadInterface;
use App\Services\Paperclip\Payloads\AgentRunPayload;
use Illuminate\Support\Facades\Log;

class AgentRunFinishedHandler implements PaperclipEventHandlerInterface
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
            Log::warning('Paperclip webhook agent.run.finished: run not found', [
                'paperclip_issue_id' => $payload->issueId,
            ]);

            return;
        }

        if ($run->status === AgentTaskRunStatus::COMPLETED || $run->status === AgentTaskRunStatus::FAILED) {
            return;
        }

        $task = $run->task;

        if (! $task) {
            Log::warning('Paperclip webhook agent.run.finished: task not found for run', [
                'agent_task_run_id'  => $run->id,
                'paperclip_issue_id' => $payload->issueId,
            ]);

            return;
        }

        $run->update([
            'status'        => AgentTaskRunStatus::COMPLETED->value,
            'output'        => $payload->output,
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

        Log::info('Paperclip agent run finished', [
            'agent_task_id'      => $task->id,
            'agent_task_run_id'  => $run->id,
            'paperclip_issue_id' => $payload->issueId,
        ]);

        try {
            $this->flowProgressService->handleTaskCompleted($task, $run);
        } catch (\Throwable $e) {
            Log::warning('Paperclip webhook: flow progress failed after task completion', [
                'agent_task_id'     => $task->id,
                'agent_task_run_id' => $run->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }
}
