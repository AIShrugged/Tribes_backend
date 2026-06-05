<?php

namespace App\Jobs;

use App\Enums\AgendaStatus;
use App\Models\AgendaTemplate;
use App\Models\CalendarEvent;
use App\Models\MeetingAgenda;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use App\Services\Agenda\AgendaRenderer;
use App\Services\CalendarEventOrganizationResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendAgendaNotificationsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CalendarEvent $calendarEvent,
    ) {}

    public function handle(): void
    {
        $agendas = MeetingAgenda::query()
            ->where('calendar_event_id', $this->calendarEvent->id)
            ->where('status', AgendaStatus::DONE)
            ->whereNull('sent_at')
            ->with('user.telegramUser')
            ->get();

        if ($agendas->isEmpty()) {
            return;
        }

        foreach ($agendas as $agenda) {
            if ($agenda->isGeneral()) {
                $this->sendGeneralToTelegram($agenda);
            } else {
                $this->sendPersonalToTelegram($agenda);
            }

            $agenda->update(['sent_at' => now()]);
        }
    }

    private function sendGeneralToTelegram(MeetingAgenda $agenda): void
    {
        $user = $this->calendarEvent->source?->user;
        if (! $user) {
            return;
        }

        $orgId = $this->calendarEvent->source?->organization_id;
        $teams = app(\App\Services\UserTeamsResolver::class)->forOutboundNotification($user, $orgId);

        foreach ($teams as $team) {
            $settings = TeamNotificationSetting::query()
                ->where('team_id', $team->id)
                ->where('event_type', 'meeting_agenda')
                ->where('enabled', true)
                ->where('notifiable_type', TelegramChatRegistration::class)
                ->with('notifiable')
                ->get();

            foreach ($settings as $setting) {
                $registration = $setting->notifiable;
                if (! $registration?->telegram_chat_id) {
                    continue;
                }

                $this->sendTelegramMessage(
                    $registration->telegram_chat_id,
                    $this->formatTelegramMessage($agenda, $this->calendarEvent),
                    $registration->message_thread_id,
                );
            }
        }
    }

    private function sendPersonalToTelegram(MeetingAgenda $agenda): void
    {
        $telegramUser = $agenda->user?->telegramUser;
        if (! $telegramUser?->telegram_user_id) {
            return;
        }

        $this->sendTelegramMessage(
            $telegramUser->telegram_user_id,
            $this->formatTelegramMessage($agenda, $this->calendarEvent),
        );
    }

    /**
     * Telegram counts message length in UTF-16 code units, with a hard limit of 4096.
     * Each emoji (e.g. 📅 🤖 🔵) is a surrogate pair → 2 UTF-16 units → emoji-heavy
     * agendas can blow past 4096 even when PHP's mb_strlen (code-point count) reports
     * fewer chars. We measure in UTF-16 to match TG and keep a buffer for the
     * "(part X/Y)" header we may prepend on chunked sends.
     */
    private const TG_TEXT_MAX = 3900;

    private function sendTelegramMessage(int|string $chatId, string $text, ?int $messageThreadId = null): void
    {
        $chunks = $this->chunkForTelegram($text);

        $telegram = new Api(config('telegram.bot_token'));
        $total = count($chunks);

        foreach ($chunks as $idx => $chunk) {
            $body = $total > 1
                ? '<i>(part '.($idx + 1).'/'.$total.')</i>'."\n\n".$chunk
                : $chunk;

            try {
                $params = [
                    'chat_id' => $chatId,
                    'text' => $body,
                    'parse_mode' => 'HTML',
                ];
                if ($messageThreadId) {
                    $params['message_thread_id'] = $messageThreadId;
                }
                $telegram->sendMessage($params);
            } catch (\Throwable $e) {
                Log::warning('SendAgendaNotificationsJob: failed to send Telegram message', [
                    'chat_id' => $chatId,
                    'agenda_id' => $this->calendarEvent->id,
                    'chunk' => ($idx + 1).'/'.$total,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Split a HTML/plain message into chunks within TG's 4096 UTF-16-code-unit limit.
     * Prefers paragraph boundaries (`\n\n`), then line breaks, then hard-cut.
     * Caller is responsible for not splitting inside a single inline HTML tag —
     * agenda renderer emits one tag per line so paragraph boundaries are safe.
     *
     * @return string[]
     */
    private function chunkForTelegram(string $text): array
    {
        $text = trim($text);
        if ($this->utf16Length($text) <= self::TG_TEXT_MAX) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while ($this->utf16Length($remaining) > self::TG_TEXT_MAX) {
            // Find the largest code-point prefix that fits in TG_TEXT_MAX UTF-16 units.
            $headEndCp = $this->codePointPrefixForUtf16Budget($remaining, self::TG_TEXT_MAX);
            $head = mb_substr($remaining, 0, $headEndCp);

            // Prefer paragraph, then line, then space — fall back to hard cut.
            $cut = $this->lastSeparator($head, ["\n\n", "\n", ' ']);
            if ($cut <= 0) {
                $cut = $headEndCp;
            }

            $chunks[] = trim(mb_substr($remaining, 0, $cut));
            $remaining = ltrim(mb_substr($remaining, $cut));
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    /**
     * Length of the string in UTF-16 code units — what Telegram counts against the 4096 limit.
     * BMP chars = 1 unit, non-BMP chars (most emoji) = 2 units (surrogate pair).
     */
    private function utf16Length(string $text): int
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return is_string($utf16) ? (int) (strlen($utf16) / 2) : mb_strlen($text);
    }

    /**
     * Largest code-point count from the start of $text whose UTF-16 length ≤ $budget.
     * Linear scan accumulating per-char UTF-16 size (1 or 2 units).
     */
    private function codePointPrefixForUtf16Budget(string $text, int $budget): int
    {
        $cpCount = mb_strlen($text);
        $units = 0;
        for ($i = 0; $i < $cpCount; $i++) {
            $ch = mb_substr($text, $i, 1);
            $cu = $this->utf16Length($ch);
            if ($units + $cu > $budget) {
                return $i;
            }
            $units += $cu;
        }

        return $cpCount;
    }

    /**
     * @param  string[]  $separators  ordered by preference (best first)
     */
    private function lastSeparator(string $haystack, array $separators): int
    {
        foreach ($separators as $sep) {
            $pos = mb_strrpos($haystack, $sep);
            if ($pos !== false && $pos > 0) {
                return $pos + mb_strlen($sep);
            }
        }

        return 0;
    }

    private function formatTelegramMessage(MeetingAgenda $agenda, CalendarEvent $event): string
    {
        $rawJson = $agenda->raw_json ?? [];

        if ($agenda->isGeneral() && ! empty($rawJson)) {
            $template = $this->resolveTemplate($event);

            return app(AgendaRenderer::class)->renderForTelegram($rawJson, $event, $template);
        }

        // Personal agenda — plain text fallback
        $lines = [];
        $lines[] = '<b>'.e($event->title).'</b>';
        $lines[] = '🕐 '.$event->starts_at->format('d.m.Y H:i');
        $lines[] = '';
        $lines[] = e($agenda->content);

        return implode("\n", $lines);
    }

    private function resolveTemplate(CalendarEvent $event): ?AgendaTemplate
    {
        $teamId = app(CalendarEventOrganizationResolver::class)->resolveDefaultTeamId($event);
        if (! $teamId) {
            return null;
        }

        return AgendaTemplate::where('team_id', $teamId)->first();
    }
}
