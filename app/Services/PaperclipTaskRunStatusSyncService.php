<?php

namespace App\Services;

use App\Enums\AgentTaskRunStatus;
use App\Events\AgentTaskRunFinalized;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Issue;
use App\Models\IssueAgentFlow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaperclipTaskRunStatusSyncService
{
    public function __construct(
        private readonly IssueAgentFlowProgressService $flowProgressService,
        private readonly PaperclipIssueSyncService $issueSyncService,
    ) {}

    public function applyCallback(
        AgentTaskRun $run,
        string $status,
        string $comment,
        array $artifacts = [],
    ): bool {
        $run->loadMissing('task');

        $task = $run->task;
        if (! $task) {
            return false;
        }

        $terminalStatuses = [
            AgentTaskRunStatus::COMPLETED,
            AgentTaskRunStatus::FAILED,
            AgentTaskRunStatus::PAUSED,
        ];

        if (in_array($run->status, $terminalStatuses, true)) {
            return false;
        }

        $this->recordCallbackMetadata($run, $status, $comment, $artifacts);

        return match ($status) {
            'done' => $this->completeRun($run, $task, $comment, $artifacts),
            'blocked' => $this->pauseRun($run, $task, $comment, $artifacts),
            'failed' => $this->failRun($run, $task, $comment, $artifacts),
            default => false,
        };
    }

    private function recordCallbackMetadata(AgentTaskRun $run, string $status, string $comment, array $artifacts): void
    {
        $metadata = $run->metadata ?? [];
        $metadata['paperclip_callback'] = [
            'status' => $status,
            'last_comment' => $comment,
            'artifacts' => $artifacts,
            'received_at' => now()->toIso8601String(),
        ];

        if ($artifacts !== []) {
            $metadata['paperclip_attachments'] = $artifacts;
            $metadata['paperclip_artifacts'] = $artifacts;
        }

        $run->update([
            'metadata' => $metadata,
        ]);
    }

    private function completeRun(AgentTaskRun $run, AgentTask $task, string $output, array $artifacts): bool
    {
        $run->update([
            'status' => AgentTaskRunStatus::COMPLETED->value,
            'output' => $output,
            'finished_at' => now(),
            'error_message' => null,
        ]);

        $task->update([
            'enabled' => $task->isOneOff() ? false : $task->enabled,
            'next_run_at' => $task->nextRunFrom($run->scheduled_for ?? now()),
            'last_completed_at' => now(),
            'last_failed_at' => null,
            'last_error' => null,
            'locked_at' => null,
        ]);

        Log::info('Paperclip callback completed task run', [
            'agent_task_id' => $task->id,
            'agent_task_run_id' => $run->id,
            'paperclip_issue_id' => $run->paperclip_issue_id,
        ]);

        try {
            $this->flowProgressService->handleTaskCompleted($task, $run);
        } catch (\Throwable $e) {
            Log::warning('Paperclip callback: flow progress failed after task completion', [
                'agent_task_id' => $task->id,
                'agent_task_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->syncIssue($run, 'done', $output, $artifacts);

        AgentTaskRunFinalized::dispatch($run->fresh(), AgentTaskRunStatus::COMPLETED);

        return true;
    }

    private function pauseRun(AgentTaskRun $run, AgentTask $task, string $blockedReason, array $artifacts): bool
    {
        $run->update([
            'status' => AgentTaskRunStatus::PAUSED->value,
            'error_message' => $blockedReason,
            'finished_at' => now(),
        ]);

        $task->update([
            'enabled' => false,
            'locked_at' => null,
            'last_failed_at' => now(),
            'last_error' => $blockedReason,
        ]);

        Log::info('Paperclip callback blocked task run', [
            'agent_task_id' => $task->id,
            'agent_task_run_id' => $run->id,
            'paperclip_issue_id' => $run->paperclip_issue_id,
            'reason' => Str::limit($blockedReason, 200),
        ]);

        $this->syncIssue($run, 'blocked', $blockedReason, $artifacts);

        AgentTaskRunFinalized::dispatch($run->fresh(), AgentTaskRunStatus::PAUSED);

        try {
            $this->flowProgressService->handleTaskFailed($task, $run);
        } catch (\Throwable $e) {
            Log::warning('Paperclip callback: flow progress failed after task pause', [
                'agent_task_id' => $task->id,
                'agent_task_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function failRun(AgentTaskRun $run, AgentTask $task, string $errorMessage, array $artifacts): bool
    {
        $run->update([
            'status' => AgentTaskRunStatus::FAILED->value,
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ]);

        $task->update([
            'last_failed_at' => now(),
            'last_error' => $errorMessage,
            'locked_at' => null,
            'next_run_at' => $task->isInterval()
                ? $task->nextRunFrom($run->scheduled_for ?? now())
                : $task->next_run_at,
        ]);

        Log::info('Paperclip callback failed task run', [
            'agent_task_id' => $task->id,
            'agent_task_run_id' => $run->id,
            'paperclip_issue_id' => $run->paperclip_issue_id,
            'error' => $errorMessage,
        ]);

        $this->syncIssue($run, 'failed', $errorMessage, $artifacts);

        AgentTaskRunFinalized::dispatch($run->fresh(), AgentTaskRunStatus::FAILED);

        try {
            $this->flowProgressService->handleTaskFailed($task, $run);
        } catch (\Throwable $e) {
            Log::warning('Paperclip callback: flow progress failed after task failure', [
                'agent_task_id' => $task->id,
                'agent_task_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function syncIssue(AgentTaskRun $run, string $status, ?string $comment, array $artifacts): void
    {
        $issueModel = $this->resolveIssue($run);

        if (! $issueModel) {
            return;
        }

        try {
            $syncStatus = $status;

            if ($status === 'done' && ! $this->canMarkIssueDone($run)) {
                $syncStatus = null;
            }

            $this->issueSyncService->sync($issueModel, $run, $syncStatus, $comment, $artifacts);
        } catch (\Throwable $e) {
            Log::warning('Paperclip callback: issue sync failed', [
                'agent_task_run_id' => $run->id,
                'issue_id' => $issueModel->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveIssue(AgentTaskRun $run): ?Issue
    {
        $run->loadMissing('task');
        $task = $run->task;

        if (! $task) {
            return null;
        }

        $issueId = data_get($task->metadata, 'issue_id')
            ?? data_get($task->input_payload, 'issue.id')
            ?? data_get($task->input_payload, 'flow.issue_id');
        if ($issueId) {
            return Issue::find($issueId);
        }

        $flowId = data_get($task->metadata, 'issue_agent_flow_id')
            ?? data_get($task->input_payload, 'flow.issue_agent_flow_id');
        if ($flowId) {
            $flow = IssueAgentFlow::find($flowId);
            return $flow?->issue;
        }

        return null;
    }

    private function canMarkIssueDone(AgentTaskRun $run): bool
    {
        $issue = $this->resolveIssue($run);
        if (! $issue) {
            return true;
        }

        $flow = $issue->agentFlow()->first();
        if (! $flow) {
            return true;
        }

        return ! $flow->steps()
            ->where('status', '!=', \App\Enums\IssueAgentFlowStepStatus::SUCCEEDED->value)
            ->exists();
    }
}
