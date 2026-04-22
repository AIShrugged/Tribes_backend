<?php

namespace App\Listeners;

use App\Events\MeetingTasksExtracted;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class NotifyCalendarOwnerAboutCreatedTasks
{
    public function handle(MeetingTasksExtracted $event): void
    {
        $calendarEvent = $event->calendarEvent;
        $user = $calendarEvent->source?->user;

        if (! $user) {
            return;
        }

        $user->loadMissing('telegramUser');

        if (! $user->telegramUser?->telegram_user_id) {
            return;
        }

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $user->telegramUser->telegram_user_id,
                'text'                     => $this->formatMessage($calendarEvent, $event->issues),
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyCalendarOwnerAboutCreatedTasks: failed to send', [
                'user_id'          => $user->id,
                'telegram_user_id' => $user->telegramUser->telegram_user_id,
                'calendar_event_id' => $calendarEvent->id,
                'error'            => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage($calendarEvent, $issues): string
    {
        $meetingTitle = e($calendarEvent->title ?? 'встреча');
        $frontendUrl  = rtrim(config('app.frontend_url'), '/');

        $lines = [];
        $lines[] = "📋 По итогам встречи <b>{$meetingTitle}</b> от вашего имени были созданы задачи:";
        $lines[] = '';

        foreach ($issues as $i => $issue) {
            $num      = $i + 1;
            $title    = e($issue->name);
            $url      = "{$frontendUrl}/dashboard/issues/{$issue->id}";
            $assignee = $issue->assignee_name ? ' → ' . e($issue->assignee_name) : '';
            $due      = $issue->due_date ? ' <i>(' . $issue->due_date->format('d.m.Y') . ')</i>' : '';
            $lines[]  = "{$num}. <a href=\"{$url}\">{$title}</a>{$assignee}{$due}";
        }

        return implode("\n", $lines);
    }
}
