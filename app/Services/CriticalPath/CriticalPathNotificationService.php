<?php

namespace App\Services\CriticalPath;

use App\Models\CriticalPathGraph;
use App\Models\Issue;
use App\Models\Team;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class CriticalPathNotificationService
{
    public function notifyTeam(CriticalPathGraph $graph): void
    {
        $targetTeamId = $this->resolveNotificationTeamId($graph);

        if ($targetTeamId === null) {
            return;
        }

        $settings = TeamNotificationSetting::query()
            ->where('team_id', $targetTeamId)
            ->where('event_type', 'critical_path')
            ->where('enabled', true)
            ->where('notifiable_type', TelegramChatRegistration::class)
            ->with('notifiable')
            ->get();

        if ($settings->isEmpty()) {
            return;
        }

        $graph->loadMissing(['nodes.issue.assignee']);

        $criticalNodes = $graph->nodes
            ->where('node_type', 'issue')
            ->where('is_critical', true)
            ->sortBy('early_start');

        $otherNodes = $graph->nodes
            ->where('node_type', 'issue')
            ->where('is_critical', false)
            ->sortByDesc('slack');

        // Debounce: skip if the critical path is identical to what we last notified about.
        // Without this, any trivial edit to any open task re-sends the full digest twice/day.
        $signature = $this->criticalPathSignature($criticalNodes);
        if ($graph->last_notified_signature === $signature) {
            return;
        }

        // org-level graph has no team relation — point it at the resolved (default) team so the
        // message header shows a real name instead of the literal "команда".
        $graph->setRelation('team', Team::find($targetTeamId));

        $text = $this->formatTeamMessage($graph, $criticalNodes, $otherNodes);

        $anySent = false;
        foreach ($settings as $setting) {
            /** @var TelegramChatRegistration $registration */
            $registration = $setting->notifiable;

            if (! $registration || ! $registration->telegram_chat_id) {
                continue;
            }

            // Defense-in-depth: never send to a chat from another organization.
            if ((int) $registration->organization_id !== (int) $graph->organization_id) {
                continue;
            }

            $anySent = $this->sendToChat($registration, $text) || $anySent;
        }

        // Only mark this critical-path state as notified once at least one delivery succeeded —
        // otherwise a transient Telegram outage would permanently debounce (tries=1, no resend).
        if ($anySent) {
            $graph->update(['last_notified_signature' => $signature]);
        }
    }

    /**
     * Which team's critical_path settings drive this graph's notification — and a null "skip" signal.
     *
     * In the no-teams model graphs are org-level (team_id=null) and must be driven by the org's
     * default-team settings. A graph scoped to the default team is suppressed to avoid duplicating
     * the org-level graph's send; a graph scoped to a real (non-default) team uses its own settings.
     */
    private function resolveNotificationTeamId(CriticalPathGraph $graph): ?int
    {
        $defaultTeamId = $graph->organization_id
            ? Team::where('organization_id', $graph->organization_id)->where('is_default', true)->value('id')
            : null;

        if ($graph->team_id === null) {
            return $defaultTeamId;
        }

        if ($defaultTeamId !== null && (int) $graph->team_id === (int) $defaultTeamId) {
            return null; // covered by the org-level graph — avoid duplicate send
        }

        return (int) $graph->team_id;
    }

    private function criticalPathSignature(Collection $criticalNodes): string
    {
        return md5(json_encode([
            'critical' => $criticalNodes->pluck('issue_id')->filter()->sort()->values()->all(),
            'duration' => (string) ($criticalNodes->max('early_finish') ?? 0),
        ]));
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

    public function notifyParticipantBatch(User $user, Collection $items, ?int $overrideTelegramUserId = null): bool
    {
        $telegramUserId = $overrideTelegramUserId ?? $user->telegramUser?->telegram_user_id;

        if (! $telegramUserId || $items->isEmpty()) {
            return false;
        }

        $text = $this->formatParticipantBatchMessage($user, $items);

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id' => $telegramUserId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('CriticalPathNotificationService: failed to notify participant batch', [
                'user_id' => $user->id,
                'telegram_user_id' => $telegramUserId,
                'issue_ids' => $items->pluck('issue.id')->filter()->values()->all(),
                'error' => $e->getMessage(),
            ]);

            return false;
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

    private function formatParticipantBatchMessage(User $user, Collection $items): string
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        $executor = $items->filter(fn ($item) => $item['issue']->assignee_id === $user->id);
        $assigner = $items->filter(fn ($item) => $item['issue']->user_id === $user->id && $item['issue']->assignee_id !== $user->id);

        $lines = [];
        $lines[] = '📋 <b>Критический путь</b>';

        if ($executor->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '⚡️ <b>Исполнитель:</b>';
            foreach ($executor->values() as $item) {
                $issue = $item['issue'];
                $node = $item['node'];
                $url = "{$frontendUrl}/dashboard/issues/{$issue->id}";
                $dur = round($node->duration_days, 1);
                $due = $issue->due_date ? ' · 📅 '.$issue->due_date->format('d.m') : '';
                $lines[] = "• <a href=\"{$url}\">".e($issue->name)."</a> — {$dur}д{$due}";
            }
        }

        if ($assigner->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📝 <b>Постановщик:</b>';
            foreach ($assigner->values() as $item) {
                $issue = $item['issue'];
                $node = $item['node'];
                $url = "{$frontendUrl}/dashboard/issues/{$issue->id}";
                $assigneeName = $issue->assignee ? ' · '.e($issue->assignee->name) : '';
                $dur = round($node->duration_days, 1);
                $due = $issue->due_date ? ' · 📅 '.$issue->due_date->format('d.m') : '';
                $lines[] = "• <a href=\"{$url}\">".e($issue->name)."</a>{$assigneeName} — {$dur}д{$due}";
            }
        }

        return implode("\n", $lines);
    }

    private function sendToChat(TelegramChatRegistration $registration, string $text): bool
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

            return true;
        } catch (\Throwable $e) {
            Log::warning('CriticalPathNotificationService: failed to send team message', [
                'telegram_chat_id' => $registration->telegram_chat_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
