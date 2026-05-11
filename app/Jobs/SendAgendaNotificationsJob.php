<?php

namespace App\Jobs;

use App\Enums\AgendaStatus;
use App\Models\CalendarEvent;
use App\Models\MeetingAgenda;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use App\Services\Agenda\AgendaRenderer;
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
        if (!$user) {
            return;
        }

        $teams = $user->teams;

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
                if (!$registration?->telegram_chat_id) {
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
        if (!$telegramUser?->telegram_user_id) {
            return;
        }

        $this->sendTelegramMessage(
            $telegramUser->telegram_user_id,
            $this->formatTelegramMessage($agenda, $this->calendarEvent),
        );
    }

    private function sendTelegramMessage(int|string $chatId, string $text, ?int $messageThreadId = null): void
    {
        try {
            $telegram = new Api(config('telegram.bot_token'));
            $params = [
                'chat_id' => $chatId,
                'text' => $text,
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
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatTelegramMessage(MeetingAgenda $agenda, CalendarEvent $event): string
    {
        $rawJson = $agenda->raw_json ?? [];

        if ($agenda->isGeneral() && !empty($rawJson)) {
            return AgendaRenderer::renderForTelegram($rawJson, $event);
        }

        // Personal agenda — plain text fallback
        $lines   = [];
        $lines[] = '<b>' . e($event->title) . '</b>';
        $lines[] = '🕐 ' . $event->starts_at->format('d.m.Y H:i');
        $lines[] = '';
        $lines[] = e($agenda->content);

        return implode("\n", $lines);
    }
}
