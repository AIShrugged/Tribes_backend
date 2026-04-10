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

        if ($summary->summary) {
            $lines[] = $this->markdownToTelegramHtml($summary->summary);
        }

        return implode("\n", $lines);
    }

    private function markdownToTelegramHtml(string $markdown): string
    {
        $rows = explode("\n", $markdown);
        $result = [];
        $skipNextRow = false;

        foreach ($rows as $line) {
            // Table separator |---|---| — skip, but mark next row is data (not header)
            if (preg_match('/^\|[-| :]+\|$/', trim($line))) {
                $skipNextRow = false;
                continue;
            }

            // Table row
            if (preg_match('/^\|(.+)\|$/', trim($line))) {
                if ($skipNextRow) {
                    // Header row — skip, treat next as data
                    $skipNextRow = false;
                    continue;
                }
                $cells = array_values(array_filter(
                    array_map('trim', explode('|', trim($line, '|'))),
                    fn ($c) => $c !== '',
                ));
                $escaped = array_map(fn ($c) => e(strip_tags($c)), $cells);
                $result[] = '• ' . implode(' — ', $escaped);
                continue;
            }

            // Headings ## → <b>text</b>
            if (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
                $result[] = '<b>' . e(trim($m[1])) . '</b>';
                continue;
            }

            // List items - text → • text
            if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
                $text = $this->inlineMarkdown($m[1]);
                $result[] = '• ' . $text;
                continue;
            }

            // Empty line
            if (trim($line) === '') {
                $result[] = '';
                continue;
            }

            // Regular text
            $result[] = $this->inlineMarkdown($line);
        }

        $output = implode("\n", $result);
        // Collapse 3+ newlines to 2
        $output = preg_replace('/\n{3,}/', "\n\n", $output);

        return trim($output);
    }

    private function inlineMarkdown(string $text): string
    {
        // Escape HTML first, then convert **bold** and _italic_
        $text = e($text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $text);
        $text = preg_replace('/__(.+?)__/', '<b>$1</b>', $text);
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<i>$1</i>', $text);
        $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/', '<i>$1</i>', $text);

        return $text;
    }
}
