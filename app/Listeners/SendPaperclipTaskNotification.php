<?php

namespace App\Listeners;

use App\Enums\AgentTaskExecutionMode;
use App\Enums\AgentTaskRunStatus;
use App\Events\AgentTaskRunFinalized;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Issue;
use App\Models\IssueAgentFlow;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Telegram\Bot\Api;

class SendPaperclipTaskNotification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public function handle(AgentTaskRunFinalized $event): void
    {
        $run = $event->run;
        $run->loadMissing('task');

        $task = $run->task;
        if (! $task || $task->execution_mode !== AgentTaskExecutionMode::PAPERCLIP) {
            return;
        }

        $issue = $this->resolveIssue($task);
        $targets = $this->resolveTargets($task, $issue);

        if (empty($targets)) {
            return;
        }

        $text = $this->buildMessage($task, $run, $event->status, $issue);

        foreach ($targets as $target) {
            $this->send($target['chat_id'], $text, $target['thread_id'] ?? null);
        }
    }

    /**
     * Resolve list of Telegram targets for the finalized run.
     *
     * Personal target: issue.assignee_id (fallback: task.user_id) → that user's telegramUser.
     * Team override: task.notification_telegram_chat_id (if set), sent in addition.
     *
     * Returns [] when nothing to send (e.g. no linked Issue, no telegramUser, no override).
     *
     * @return array<int, array{chat_id: string, thread_id: ?int, kind: 'personal'|'team_override'}>
     */
    public function resolveTargets(AgentTask $task, ?Issue $issue): array
    {
        $targets = [];

        if ($issue) {
            $targetUserId = $issue->assignee_id ?: $task->user_id;
            $targetUser = $targetUserId ? User::with('telegramUser')->find($targetUserId) : null;
            $personalChatId = $targetUser?->telegramUser?->telegram_user_id;

            if ($personalChatId) {
                $targets[] = [
                    'chat_id'   => (string) $personalChatId,
                    'thread_id' => null,
                    'kind'      => 'personal',
                ];
            }
        }

        if ($task->notification_telegram_chat_id) {
            $targets[] = [
                'chat_id'   => (string) $task->notification_telegram_chat_id,
                'thread_id' => $task->notification_telegram_thread_id,
                'kind'      => 'team_override',
            ];
        }

        return $targets;
    }

    public function resolveIssue(AgentTask $task): ?Issue
    {
        $issueId = data_get($task->metadata, 'issue_id')
            ?? data_get($task->input_payload, 'issue.id')
            ?? data_get($task->input_payload, 'flow.issue_id');
        if ($issueId) {
            return Issue::find($issueId);
        }

        $flowId = data_get($task->metadata, 'issue_agent_flow_id')
            ?? data_get($task->input_payload, 'flow.issue_agent_flow_id');
        if ($flowId) {
            return IssueAgentFlow::find($flowId)?->issue;
        }

        return null;
    }

    public function buildMessage(AgentTask $task, AgentTaskRun $run, AgentTaskRunStatus $status, ?Issue $issue): string
    {
        [$emoji, $label] = match ($status) {
            AgentTaskRunStatus::COMPLETED => ["\xE2\x9C\x85", 'выполнена'],
            AgentTaskRunStatus::FAILED    => ["\xE2\x9D\x8C", 'ошибка'],
            AgentTaskRunStatus::PAUSED    => ["\xE2\x8F\xB8",  'заблокирована'],
            default                       => ["\xE2\x84\xB9\xEF\xB8\x8F", $status->value],
        };

        $lines = [
            "{$emoji} <b>Agent Task #{$task->id}: " . e($label) . '</b>',
            '',
            '<b>Task:</b> ' . e((string) $task->name),
            "<b>Run:</b> #{$run->id}",
        ];

        if ($run->paperclip_issue_id) {
            $lines[] = '<b>Paperclip issue:</b> ' . e((string) $run->paperclip_issue_id);
        }

        if ($issue?->pr_url) {
            $prLabel = $issue->pr_repository && $issue->pr_number
                ? "{$issue->pr_repository}#{$issue->pr_number}"
                : 'PR';
            $lines[] = '';
            $lines[] = '<b>PR:</b> <a href="' . e($issue->pr_url) . '">' . e($prLabel) . '</a>';
        }

        $body = match ($status) {
            AgentTaskRunStatus::COMPLETED => (string) ($run->output ?? ''),
            AgentTaskRunStatus::FAILED    => (string) ($run->error_message ?? ''),
            AgentTaskRunStatus::PAUSED    => (string) ($run->error_message ?? ''),
            default                       => '',
        };

        if ($body !== '') {
            $bodyHeader = match ($status) {
                AgentTaskRunStatus::COMPLETED => '<b>Результат:</b>',
                AgentTaskRunStatus::FAILED    => '<b>Ошибка:</b>',
                AgentTaskRunStatus::PAUSED    => '<b>Причина:</b>',
                default                       => '',
            };
            $lines[] = '';
            $lines[] = $bodyHeader;
            $lines[] = e(Str::limit($body, 800));
        }

        $artifacts = $this->collectArtifacts($run);
        if (! empty($artifacts)) {
            $lines[] = '';
            $lines[] = '<b>Артефакты:</b>';
            foreach (array_slice($artifacts, 0, 10) as $a) {
                $name = $a['name'];
                $url  = $a['url'];
                $lines[] = $url
                    ? '• <a href="' . e($url) . '">' . e($name) . '</a>'
                    : '• ' . e($name);
            }
        }

        if ($issue) {
            $frontend = rtrim((string) config('app.frontend_url'), '/');
            if ($frontend !== '') {
                $issueUrl = "{$frontend}/dashboard/issues/{$issue->id}";
                $lines[] = '';
                $lines[] = '🔗 <a href="' . e($issueUrl) . '">Открыть задачу</a>';
            }
        }

        if ($status === AgentTaskRunStatus::PAUSED) {
            $lines[] = '';
            $lines[] = '<i>Задача приостановлена. Устраните блокировку и запустите задачу повторно.</i>';
        }

        return implode("\n", $lines);
    }

    /**
     * Normalize artifact entries from `paperclip_attachments` / `paperclip_artifacts` into
     * display items. Paperclip sends `['id' => ..., 'filename' => ..., 'mimeType' => ..., 'size' => ...]`
     * (no URL); legacy/synthetic entries may have `url`/`href`/`link`.
     *
     * @return array<int, array{name: string, url: ?string}>
     */
    private function collectArtifacts(AgentTaskRun $run): array
    {
        $candidates = (array) ($run->metadata['paperclip_attachments']
            ?? $run->metadata['paperclip_artifacts']
            ?? []);

        $items = [];
        $seen = [];
        foreach ($candidates as $a) {
            $name = null;
            $url = null;

            if (is_string($a)) {
                $url = $a;
                $name = basename($a);
            } elseif (is_array($a)) {
                $name = $a['filename'] ?? $a['name'] ?? null;
                $url = $a['url'] ?? $a['href'] ?? $a['link'] ?? null;
                if (! $name && is_string($url)) {
                    $name = basename($url);
                }
            }

            if (! is_string($name) || $name === '') {
                continue;
            }

            $key = $name . '|' . (is_string($url) ? $url : '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'name' => $name,
                'url'  => is_string($url) && $url !== '' ? $url : null,
            ];
        }

        return $items;
    }

    private function send(string $chatId, string $text, ?int $threadId = null): void
    {
        try {
            $telegram = new Api(config('telegram.bot_token'));
            $params = [
                'chat_id'                  => $chatId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ];
            if ($threadId) {
                $params['message_thread_id'] = $threadId;
            }

            $telegram->sendMessage($params);
        } catch (\Throwable $e) {
            Log::warning('SendPaperclipTaskNotification: failed to send Telegram message', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
