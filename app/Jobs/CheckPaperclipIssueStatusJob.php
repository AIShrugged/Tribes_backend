<?php

namespace App\Jobs;

use App\Enums\AgentTaskRunStatus;
use App\Events\AgentTaskRunFinalized;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Issue;
use App\Models\IssueAgentFlow;
use App\Models\IssueComment;
use App\Services\IssueAgentFlowProgressService;
use App\Services\PaperclipActivitySyncService;
use App\Services\PaperclipApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        PaperclipActivitySyncService $activitySync,
    ): void {
        $run = AgentTaskRun::with('task')->find($this->agentTaskRunId);

        if (! $run) {
            return;
        }

        if (
            $run->status === AgentTaskRunStatus::COMPLETED
            || $run->status === AgentTaskRunStatus::FAILED
            || $run->status === AgentTaskRunStatus::PAUSED
        ) {
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
            $this->syncActivity($activitySync, $run);
            $this->syncAttachments($client, $run, $issueId);
            $output = $this->extractOutput($issue, $issueId, $client);
            $this->syncPrUrl($task, $run, $output, $client, $issueId);
            $this->completeRun($run, $task, $output, $flowProgressService);

            return;
        }

        if ($status === 'blocked') {
            $this->syncActivity($activitySync, $run);
            $blockedReason = $this->extractBlockedReason($issueId, $client);
            $this->pauseRun($run, $task, $blockedReason, $flowProgressService);

            return;
        }

        if ($status === 'cancelled') {
            $this->syncActivity($activitySync, $run);
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

    private function syncActivity(PaperclipActivitySyncService $activitySync, AgentTaskRun $run): void
    {
        try {
            $activitySync->syncForIssue($run);
        } catch (\Throwable $e) {
            Log::warning('Paperclip: activity sync failed', [
                'agent_task_run_id'  => $run->id,
                'paperclip_issue_id' => $run->paperclip_issue_id,
                'error'              => $e->getMessage(),
            ]);
        }
    }

    private function syncPrUrl(AgentTask $task, AgentTaskRun $run, string $output, PaperclipApiClient $client, string $paperclipIssueId): void
    {
        try {
            // Try to find PR URL in output first, then in comments
            $prUrl = $this->extractPrUrlFromText($output);

            if (! $prUrl) {
                $comments = $client->getIssueComments($paperclipIssueId);
                foreach (array_reverse($comments) as $comment) {
                    $prUrl = $this->extractPrUrlFromText($comment['body'] ?? '');
                    if ($prUrl) {
                        break;
                    }
                }
            }

            if (! $prUrl) {
                return;
            }

            // Parse repo and PR number from URL
            if (! preg_match('#https://github\.com/([^/]+/[^/]+)/pull/(\d+)#', $prUrl, $matches)) {
                return;
            }

            $repository = $matches[1];
            $prNumber = (int) $matches[2];

            // Find the Tribes issue linked to this task via IssueAgentFlow
            $flowId = $task->metadata['issue_agent_flow_id'] ?? null;
            $issueId = $task->metadata['issue_id'] ?? null;

            $issue = null;
            if ($issueId) {
                $issue = Issue::find($issueId);
            } elseif ($flowId) {
                $flow = IssueAgentFlow::find($flowId);
                $issue = $flow?->issue;
            }

            if (! $issue) {
                return;
            }

            // Update Issue with PR info
            $issue->update([
                'pr_url' => $prUrl,
                'pr_number' => $prNumber,
                'pr_repository' => $repository,
            ]);

            // Create a comment on the issue
            IssueComment::create([
                'issue_id' => $issue->id,
                'user_id' => $task->user_id,
                'content' => "PR создан агентом Paperclip: [{$repository}#{$prNumber}]({$prUrl})",
            ]);

            Log::info('Paperclip: PR URL synced to issue', [
                'issue_id' => $issue->id,
                'pr_url' => $prUrl,
                'pr_repository' => $repository,
                'pr_number' => $prNumber,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Paperclip: failed to sync PR URL', [
                'agent_task_id' => $task->id,
                'agent_task_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractPrUrlFromText(string $text): ?string
    {
        if (preg_match('#https://github\.com/[^/]+/[^/]+/pull/\d+#', $text, $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function syncAttachments(PaperclipApiClient $client, AgentTaskRun $run, string $issueId): void
    {
        try {
            $attachments = $client->getIssueAttachments($issueId);

            if (! empty($attachments)) {
                $run->update([
                    'metadata' => array_merge($run->metadata ?? [], [
                        'paperclip_attachments' => $attachments,
                    ]),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Paperclip: attachment sync failed', [
                'agent_task_run_id'  => $run->id,
                'paperclip_issue_id' => $issueId,
                'error'              => $e->getMessage(),
            ]);
        }
    }

    private function extractOutput(array $issue, string $issueId, PaperclipApiClient $client): string
    {
        if (! empty($issue['planDocument'])) {
            $planDocument = $issue['planDocument'];

            // planDocument may be a wrapper object with a 'body' key containing the actual content
            if (is_array($planDocument) && ! empty($planDocument['body'])) {
                return (string) $planDocument['body'];
            }

            return is_array($planDocument) ? json_encode($planDocument, JSON_UNESCAPED_UNICODE) : (string) $planDocument;
        }

        $comments = $client->getIssueComments($issueId);

        if (! empty($comments)) {
            $last = end($comments);

            return $last['body'] ?? '';
        }

        return '';
    }

    private function extractBlockedReason(string $issueId, PaperclipApiClient $client): string
    {
        try {
            $comments = $client->getIssueComments($issueId);

            if (! empty($comments)) {
                $last = end($comments);

                return $last['body'] ?? 'Задача заблокирована в Paperclip.';
            }
        } catch (\Throwable) {
            // ignore, fall through to default
        }

        return 'Задача заблокирована в Paperclip.';
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

        AgentTaskRunFinalized::dispatch($run->fresh(), AgentTaskRunStatus::COMPLETED);

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

    private function pauseRun(
        AgentTaskRun $run,
        AgentTask $task,
        string $blockedReason,
        IssueAgentFlowProgressService $flowProgressService,
    ): void {
        $run->update([
            'status'        => AgentTaskRunStatus::PAUSED->value,
            'error_message' => $blockedReason,
            'finished_at'   => now(),
        ]);

        $task->update([
            'enabled'        => false,
            'locked_at'      => null,
            'last_failed_at' => now(),
            'last_error'     => $blockedReason,
        ]);

        Log::info('Paperclip issue blocked — task paused', [
            'agent_task_id'      => $task->id,
            'agent_task_run_id'  => $run->id,
            'paperclip_issue_id' => $run->paperclip_issue_id,
            'reason'             => Str::limit($blockedReason, 200),
        ]);

        AgentTaskRunFinalized::dispatch($run->fresh(), AgentTaskRunStatus::PAUSED);

        try {
            $flowProgressService->handleTaskFailed($task, $run);
        } catch (\Throwable $e) {
            Log::warning('Paperclip: flow progress failed after task pause', [
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

        AgentTaskRunFinalized::dispatch($run->fresh(), AgentTaskRunStatus::FAILED);

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
