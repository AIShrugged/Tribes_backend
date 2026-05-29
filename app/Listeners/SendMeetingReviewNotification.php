<?php

namespace App\Listeners;

use App\Events\MeetingReviewGenerated;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use App\Services\UserTeamsResolver;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendMeetingReviewNotification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public function handle(MeetingReviewGenerated $event): void
    {
        $review = $event->review;
        $calendarEvent = $review->calendarEvent;

        if (! $calendarEvent?->source?->user) {
            return;
        }

        $user = $calendarEvent->source->user;
        $orgId = $calendarEvent->source?->organization_id;
        $teams = app(UserTeamsResolver::class)->forOutboundNotification($user, $orgId);

        if ($teams->isEmpty()) {
            return;
        }

        foreach ($teams as $team) {
            $settings = TeamNotificationSetting::query()
                ->where('team_id', $team->id)
                ->where('event_type', 'meeting_review')
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

                $this->send($registration, $review, $setting->channel_type ?? 'telegram');
            }
        }
    }

    private function send(TelegramChatRegistration $registration, $review, string $channelType): void
    {
        try {
            $text = $this->formatMessage($review, $channelType);

            $telegram = new Api(config('telegram.bot_token'));
            $params = [
                'chat_id' => $registration->telegram_chat_id,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];

            if ($registration->message_thread_id) {
                $params['message_thread_id'] = $registration->message_thread_id;
            }

            $telegram->sendMessage($params);
        } catch (\Throwable $e) {
            Log::warning('SendMeetingReviewNotification: failed to send Telegram message', [
                'telegram_chat_id' => $registration->telegram_chat_id,
                'review_id' => $review->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage($review, string $channelType = 'telegram'): string
    {
        $calendarEvent = $review->calendarEvent;
        $lines = [];

        // ── Always visible: header ──
        $scoreEmoji = $this->scoreEmoji($review->score);
        $lines[] = "{$scoreEmoji} <b>Meeting Review — {$review->score}/10</b>";
        $lines[] = '';
        $lines[] = '<b>'.e($calendarEvent?->title ?? 'Meeting').'</b>';

        // Score breakdown
        if ($review->score_breakdown) {
            $lines[] = '';
            foreach ($review->score_breakdown as $criterion => $value) {
                $label = $this->criterionLabel($criterion);
                $lines[] = "  {$label}: {$value}/10";
            }
        }

        // Key insight
        if ($review->key_insight) {
            $lines[] = '';
            $lines[] = '<b>💡 Key Insight:</b>';
            $lines[] = e($review->key_insight);
        }

        // ── Expandable: suggestions, trend, previous check ──
        $detailLines = [];

        if (! empty($review->suggestions)) {
            $detailLines[] = '<b>Suggestions:</b>';
            foreach ($review->suggestions as $i => $suggestion) {
                $detailLines[] = ($i + 1).'. '.e($suggestion);
            }
        }

        if ($review->trend) {
            $detailLines[] = '';
            $detailLines[] = '<b>📈 Trend:</b>';
            $detailLines[] = e($review->trend);
        }

        if (! empty($review->previous_suggestions_check)) {
            $detailLines[] = '';
            $detailLines[] = '<b>Previous Suggestions:</b>';
            foreach ($review->previous_suggestions_check as $check) {
                $status = strtoupper($check['status'] ?? '?');
                $emoji = match ($status) {
                    'IMPLEMENTED' => '✅',
                    'PARTIALLY' => '🔶',
                    'IGNORED' => '❌',
                    default => '❓',
                };
                $suggestion = $check['suggestion'] ?? '';
                $comment = $check['comment'] ?? '';
                $detailLines[] = "{$emoji} <b>[{$status}]</b> ".e(mb_substr($suggestion, 0, 100)).(mb_strlen($suggestion) > 100 ? '...' : '');
                if ($comment) {
                    $detailLines[] = '  → '.e($comment);
                }
            }
        }

        if (! empty($detailLines)) {
            $detailBlock = implode("\n", $detailLines);
            $lines[] = '';
            if ($channelType === 'telegram') {
                $lines[] = '<blockquote expandable>'.$detailBlock.'</blockquote>';
            } else {
                $lines[] = $detailBlock;
            }
        }

        return implode("\n", $lines);
    }

    private function scoreEmoji(float $score): string
    {
        return match (true) {
            $score >= 8 => '🟢',
            $score >= 5 => '🟡',
            default => '🔴',
        };
    }

    private function criterionLabel(string $criterion): string
    {
        return match ($criterion) {
            'goal_clarity' => '🎯 Goal clarity',
            'participation_balance' => '👥 Participation',
            'decisions_made' => '✅ Decisions',
            'time_efficiency' => '⏱ Time efficiency',
            'action_items_clarity' => '📋 Action items',
            default => $criterion,
        };
    }
}
