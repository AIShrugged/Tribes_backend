<?php

namespace App\Jobs;

use App\Enums\AgentTaskRunStatus;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Services\IssueAgentFlowProgressService;
use App\Services\PaperclipActivitySyncService;
use App\Services\PaperclipApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Telegram\Bot\Api;

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
            $this->syncActivity($activitySync, $run);
            $this->syncAttachments($client, $run, $issueId);
            $output = $this->extractOutput($issue, $issueId, $client);
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

        $this->sendBlockedTelegramNotification($task, $run, $blockedReason);

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

    private function sendBlockedTelegramNotification(AgentTask $task, AgentTaskRun $run, string $blockedReason): void
    {
        if (! $task->notification_telegram_chat_id) {
            return;
        }

        try {
            $lines = [
                "\xE2\x8F\xB8 *Agent Task #{$task->id}: заблокирован*",
                '',
                "*Task:* {$task->name}",
                "*Run:* #{$run->id}",
                "*Paperclip issue:* {$run->paperclip_issue_id}",
                '',
                '*Причина:* ' . Str::limit($blockedReason, 600),
                '',
                '_Задача приостановлена. Устраните блокировку и запустите задачу повторно._',
            ];

            $text   = implode("\n", $lines);
            $params = [
                'chat_id'    => $task->notification_telegram_chat_id,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ];

            if ($task->notification_telegram_thread_id) {
                $params['message_thread_id'] = $task->notification_telegram_thread_id;
            }

            $telegram = new Api(config('telegram.bot_token'));

            try {
                $telegram->sendMessage($params);
            } catch (\Throwable $e) {
                if (str_contains(mb_strtolower($e->getMessage()), "can't parse entities")
                    || str_contains(mb_strtolower($e->getMessage()), 'cant parse entities')) {
                    unset($params['parse_mode']);
                    $telegram->sendMessage($params);
                } else {
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Paperclip: failed to send blocked notification', [
                'agent_task_id' => $task->id,
                'chat_id'       => $task->notification_telegram_chat_id,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
