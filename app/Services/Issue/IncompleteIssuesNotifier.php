<?php

namespace App\Services\Issue;

use App\Models\CalendarEvent;
use App\Models\Issue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class IncompleteIssuesNotifier
{
    public function notify(
        CalendarEvent $event,
        Collection $issues,
        Collection $unresolvedDecisions,
        ?int $forceTelegramUserId = null,
    ): void {
        if ($issues->isEmpty() && $unresolvedDecisions->isEmpty()) {
            return;
        }

        $owner = $event->source?->user;
        if (! $owner) {
            return;
        }
        $owner->loadMissing('telegramUser');

        $chatId = $forceTelegramUserId ?? $owner->telegramUser?->telegram_user_id;
        if (! $chatId) {
            return;
        }

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $chatId,
                'text'                     => $this->formatMessage($event, $issues, $unresolvedDecisions),
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('IncompleteIssuesNotifier: failed to send', [
                'calendar_event_id' => $event->id,
                'chat_id'           => $chatId,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage(CalendarEvent $event, Collection $issues, Collection $unresolvedDecisions): string
    {
        $meetingTitle = e($event->title ?? 'встреча');
        $frontendUrl  = rtrim(config('app.frontend_url'), '/');

        $lines = [];

        if ($issues->isNotEmpty()) {
            $lines[] = "📋 По встрече <b>{$meetingTitle}</b> есть задачи без полного заполнения:";
            $lines[] = '';

            foreach ($issues as $i => $issue) {
                $num   = $i + 1;
                $title = e($issue->name);
                $url   = "{$frontendUrl}/dashboard/issues/{$issue->id}";
                $lines[] = "{$num}. <a href=\"{$url}\">{$title}</a> — " . $this->describeMissing($issue);
            }
        }

        if ($unresolvedDecisions->isNotEmpty()) {
            if (! empty($lines)) {
                $lines[] = '';
            }
            $lines[] = "⚠️ По встрече <b>{$meetingTitle}</b> есть решения без задачи-покрытия:";
            $lines[] = '';

            foreach ($unresolvedDecisions as $i => $decision) {
                $num  = $i + 1;
                $text = e(mb_substr((string) $decision->text, 0, 200));
                $lines[] = "{$num}. {$text}";
            }
        }

        $lines[] = '';
        $lines[] = 'Дозаполни в дашборде.';

        return implode("\n", $lines);
    }

    private function describeMissing(Issue $issue): string
    {
        $missing = [];
        if (empty($issue->assignee_id)) {
            $missing[] = 'не указан исполнитель';
        }
        if (empty($issue->due_date)) {
            $missing[] = 'не указан срок';
        }

        return implode(', ', $missing);
    }
}
