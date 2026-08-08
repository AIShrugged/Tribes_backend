<?php

namespace App\Listeners;

use App\Events\MeetingTasksExtracted;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendMeetingTasksPersonalNotification
{
    public function handle(MeetingTasksExtracted $event): void
    {
        $calendarEvent = $event->calendarEvent;

        $author = $calendarEvent?->source?->user;

        if (! $author) {
            return;
        }

        $telegramUser = $author->telegramUser;

        if (! $telegramUser) {
            return;
        }

        try {
            $text = $this->formatMessage($calendarEvent, $event->issues);

            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'                  => $telegramUser->telegram_user_id,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendMeetingTasksPersonalNotification: failed to send Telegram message', [
                'user_id'           => $author->id,
                'telegram_user_id'  => $telegramUser->telegram_user_id,
                'calendar_event_id' => $calendarEvent->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage($calendarEvent, $issues): string
    {
        $lines = [];

        $lines[] = '📋 <b>Your Meeting Tasks — ' . e($calendarEvent->title ?? 'Meeting') . '</b>';
        $lines[] = '';

        foreach ($issues as $i => $issue) {
            $num      = $i + 1;
            $title    = e($issue->name);
            $assignee = $issue->assignee_name ? ' → ' . e($issue->assignee_name) : '';
            $lines[]  = "{$num}. {$title}{$assignee}";
        }

        return implode("\n", $lines);
    }
}
