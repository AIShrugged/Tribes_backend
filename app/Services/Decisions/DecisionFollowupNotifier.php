<?php

namespace App\Services\Decisions;

use App\Models\Decision;
use App\Models\DecisionFollowup;
use App\Support\Telegram\TelegramErrorSanitizer;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class DecisionFollowupNotifier
{
    public function send(Decision $decision): DecisionFollowup
    {
        $decision->loadMissing(['authorUser.telegramUser', 'calendarEvent']);

        $user = $decision->authorUser;
        $telegramId = $user?->telegramUser?->telegram_user_id;

        $payload = [
            'decision_id'        => $decision->id,
            'calendar_event_id'  => $decision->calendar_event_id,
            'author_user_id'     => $user?->id,
            'author_telegram_id' => $telegramId,
        ];

        if (! $user || ! $telegramId) {
            return DecisionFollowup::create([
                'decision_id'       => $decision->id,
                'recipient_user_id' => $user?->id ?? 0,
                'sent_at'           => null,
                'status'            => DecisionFollowup::STATUS_SKIPPED,
                'payload'           => $payload + ['reason' => 'no_telegram_user'],
            ]);
        }

        $text = $this->formatMessage($decision);

        try {
            $api = new Api(config('telegram.bot_token'));
            $api->sendMessage([
                'chat_id'                  => $telegramId,
                'text'                     => $text,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            return DecisionFollowup::create([
                'decision_id'       => $decision->id,
                'recipient_user_id' => $user->id,
                'sent_at'           => now(),
                'status'            => DecisionFollowup::STATUS_SENT,
                'payload'           => $payload + ['text' => $text],
            ]);
        } catch (\Throwable $e) {
            $sanitized = TelegramErrorSanitizer::sanitize($e->getMessage());

            Log::warning('DecisionFollowupNotifier: send failed', [
                'decision_id' => $decision->id,
                'error'       => $sanitized,
            ]);

            return DecisionFollowup::create([
                'decision_id'       => $decision->id,
                'recipient_user_id' => $user->id,
                'sent_at'           => null,
                'status'            => DecisionFollowup::STATUS_FAILED,
                'payload'           => $payload,
                'error'             => $sanitized,
            ]);
        }
    }

    private function formatMessage(Decision $decision): string
    {
        $event = $decision->calendarEvent;
        $date = $event?->starts_at
            ? \Carbon\Carbon::parse($event->starts_at)->format('d.m.Y')
            : '-';

        $topic = $decision->topic ?: $this->shortenText($decision->text);
        $meetingTitle = $event?->title ?? 'встрече';

        $lines = [
            "📌 Решение от <b>{$date}</b> (встреча: " . e($meetingTitle) . ")",
            "<i>" . e($topic) . "</i>",
            '',
            'Текст решения:',
            e($decision->text),
            '',
            'Под него пока нет связанных задач. Кто возьмётся?',
        ];

        return implode("\n", $lines);
    }

    private function shortenText(string $text, int $limit = 80): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit - 1) . '…';
    }
}
