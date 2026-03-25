<?php

namespace App\Listeners;

use App\Events\MeetingSummaryGenerated;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendMeetingSummaryNotification
{
    public function handle(MeetingSummaryGenerated $event): void
    {
        $summary = $event->summary;
        $calendarEvent = $summary->calendarEvent;

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
                ->where('event_type', 'meeting_summary')
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

                $this->send($registration, $summary);
            }
        }
    }

    private function send(TelegramChatRegistration $registration, $summary): void
    {
        try {
            $text = $this->formatMessage($summary);

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
            Log::warning('SendMeetingSummaryNotification: failed to send Telegram message', [
                'telegram_chat_id' => $registration->telegram_chat_id,
                'calendar_event_id' => $summary->calendar_event_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage($summary): string
    {
        $lines = [];
        $lines[] = '📋 <b>Meeting Summary</b>';
        $lines[] = '';
        $lines[] = '<b>' . e($summary->title) . '</b>';
        $lines[] = '';
        $lines[] = e($summary->summary);

        if (! empty($summary->key_points)) {
            $lines[] = '';
            $lines[] = '<b>Key points:</b>';
            foreach ($summary->key_points as $point) {
                $lines[] = '• ' . e($point);
            }
        }

        if (! empty($summary->decisions)) {
            $lines[] = '';
            $lines[] = '<b>Decisions:</b>';
            foreach ($summary->decisions as $decision) {
                $lines[] = '• ' . e($decision);
            }
        }

        return implode("\n", $lines);
    }
}
