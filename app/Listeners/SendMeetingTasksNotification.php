<?php

namespace App\Listeners;

use App\Events\MeetingTasksExtracted;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendMeetingTasksNotification
{
    public function handle(MeetingTasksExtracted $event): void
    {
        $calendarEvent = $event->calendarEvent;

        if (! $calendarEvent?->source?->user) {
            return;
        }

        $user = $calendarEvent->source->user;
        $teams = $user->teams;

        if ($teams->isEmpty()) {
            return;
        }

        foreach ($teams as $team) {
            $settings = TeamNotificationSetting::query()
                ->where('team_id', $team->id)
                ->where('event_type', 'meeting_tasks')
                ->where('enabled', true)
                ->where('notifiable_type', TelegramChatRegistration::class)
                ->with('notifiable')
                ->get();

            foreach ($settings as $setting) {
                /** @var TelegramChatRegistration $registration */
                $registration = $setting->notifiable;

                if (! $registration || ! $registration->telegram_chat_id) {
                    continue;
                }

                $this->send($registration, $calendarEvent, $event->issues, $setting->channel_type ?? 'telegram');
            }
        }
    }

    private function send(TelegramChatRegistration $registration, $calendarEvent, $issues, string $channelType): void
    {
        try {
            $text = $this->formatMessage($calendarEvent, $issues, $channelType);

            $telegram = new Api(config('telegram.bot_token'));
            $params = [
                'chat_id'    => $registration->telegram_chat_id,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ];

            if ($registration->message_thread_id) {
                $params['message_thread_id'] = $registration->message_thread_id;
            }

            $telegram->sendMessage($params);
        } catch (\Throwable $e) {
            Log::warning('SendMeetingTasksNotification: failed to send Telegram message', [
                'telegram_chat_id' => $registration->telegram_chat_id,
                'calendar_event_id' => $calendarEvent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage($calendarEvent, $issues, string $channelType = 'telegram'): string
    {
        $lines = [];

        $lines[] = '📋 <b>Meeting Tasks — ' . e($calendarEvent->title ?? 'Meeting') . '</b>';
        $lines[] = '';

        foreach ($issues as $i => $issue) {
            $num = $i + 1;
            $title = e($issue->name);
            $assignee = $issue->assignee_name ? ' → ' . e($issue->assignee_name) : '';
            $due = $issue->due_date ? ' <i>(' . $issue->due_date->format('d.m.Y') . ')</i>' : '';
            $lines[] = "{$num}. {$title}{$assignee}{$due}";
        }

        return implode("\n", $lines);
    }
}
