<?php

namespace App\Services\CriticalPath;

use App\Models\CriticalPathGraph;
use App\Models\Issue;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class CriticalPathNotificationService
{
    public function notifyTeam(CriticalPathGraph $graph): void
    {
        if (! $graph->team_id) {
            return;
        }

        $settings = TeamNotificationSetting::query()
            ->where('team_id', $graph->team_id)
            ->where('event_type', 'critical_path')
            ->where('enabled', true)
            ->where('notifiable_type', TelegramChatRegistration::class)
            ->with('notifiable')
            ->get();

        if ($settings->isEmpty()) {
            return;
        }

        $graph->loadMissing(['nodes.issue.assignee', 'team']);

        $criticalNodes = $graph->nodes
            ->where('node_type', 'issue')
            ->where('is_critical', true)
            ->sortBy('early_start');

        $otherNodes = $graph->nodes
            ->where('node_type', 'issue')
            ->where('is_critical', false)
            ->sortByDesc('slack');

        $text = $this->formatTeamMessage($graph, $criticalNodes, $otherNodes);

        foreach ($settings as $setting) {
            /** @var TelegramChatRegistration $registration */
            $registration = $setting->notifiable;

            if (! $registration || ! $registration->telegram_chat_id) {
                continue;
            }

            $this->sendToChat($registration, $text);
        }
    }

    public function notifyParticipants(Issue $issue, array $createdSubIssues): void
    {
        $telegram = new Api(config('telegram.bot_token'));
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        $recipients = collect();

        if ($issue->assignee_id) {
            $recipients->push(User::with('telegramUser')->find($issue->assignee_id));
        }

        if ($issue->user_id && $issue->user_id !== $issue->assignee_id) {
            $recipients->push(User::with('telegramUser')->find($issue->user_id));
        }

        foreach ($recipients->filter() as $user) {
            if (! $user->telegramUser?->telegram_user_id) {
                continue;
            }

            $text = $this->formatParticipantMessage($issue, $createdSubIssues, $frontendUrl);

            try {
                $telegram->sendMessage([
                    'chat_id' => $user->telegramUser->telegram_user_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
            } catch (\Throwable $e) {
                Log::warning('CriticalPathNotificationService: failed to notify participant', [
                    'user_id' => $user->id,
                    'issue_id' => $issue->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function formatTeamMessage(
        CriticalPathGraph $graph,
        $criticalNodes,
        $otherNodes,
    ): string {
        $teamName = $graph->team?->name ?? 'команда';
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $projectDuration = $criticalNodes->max('early_finish') ?? 0;

        $lines = [];
        $lines[] = "🔴 <b>Критический путь обновлён — {$teamName}</b>";
        $lines[] = "Длина проекта: <b>{$projectDuration} рабочих дн.</b>";

        if ($criticalNodes->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "📌 Критический путь ({$criticalNodes->count()} задач):";

            foreach ($criticalNodes->values() as $i => $node) {
                $issue = $node->issue;
                $num = $i + 1;
                $name = e($issue->name);
                $url = "{$frontendUrl}/dashboard/issues/{$issue->id}";
                $assignee = $issue->assignee ? '👤 '.e($issue->assignee->name).' · ' : '';
                $due = $issue->due_date ? '📅 '.$issue->due_date->format('d.m.Y').' · ' : '';
                $dur = round($node->duration_days, 1).'д';

                $lines[] = "{$num}. <a href=\"{$url}\">{$name}</a>";
                $lines[] = "   {$assignee}{$due}⏱ {$dur}";
            }
        }

        if ($otherNodes->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '⏳ Задачи с резервом:';

            foreach ($otherNodes->take(5) as $node) {
                $issue = $node->issue;
                $name = e($issue->name);
                $url = "{$frontendUrl}/dashboard/issues/{$issue->id}";
                $slack = round($node->slack, 1);
                $lines[] = "• <a href=\"{$url}\">{$name}</a> — резерв {$slack}д";
            }
        }

        return implode("\n", $lines);
    }

    private function formatParticipantMessage(Issue $issue, array $subIssues, string $frontendUrl): string
    {
        $name = e($issue->name);
        $url = "{$frontendUrl}/dashboard/issues/{$issue->id}";

        $lines = [];
        $lines[] = "📋 <b>Задача на критическом пути:</b> <a href=\"{$url}\">{$name}</a>";
        $lines[] = '';
        $lines[] = 'Эта задача находится на критическом пути проекта и влияет на сроки всей команды.';

        if (! empty($subIssues)) {
            $lines[] = '';
            $lines[] = 'Были созданы подзадачи:';
            foreach ($subIssues as $sub) {
                $subName = e($sub->name ?? $sub['name'] ?? '');
                $subId = $sub->id ?? $sub['id'] ?? null;
                $subUrl = $subId ? "{$frontendUrl}/dashboard/issues/{$subId}" : null;
                $lines[] = $subUrl
                    ? "• <a href=\"{$subUrl}\">{$subName}</a>"
                    : "• {$subName}";
            }
        }

        return implode("\n", $lines);
    }

    private function sendToChat(TelegramChatRegistration $registration, string $text): void
    {
        try {
            $telegram = new Api(config('telegram.bot_token'));
            $params = [
                'chat_id' => $registration->telegram_chat_id,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];

            if ($registration->message_thread_id) {
                $params['message_thread_id'] = $registration->message_thread_id;
            }

            $telegram->sendMessage($params);
        } catch (\Throwable $e) {
            Log::warning('CriticalPathNotificationService: failed to send team message', [
                'telegram_chat_id' => $registration->telegram_chat_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
