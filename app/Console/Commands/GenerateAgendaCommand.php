<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAgendaJob;
use App\Models\CalendarEvent;
use App\Models\TelegramChatRegistration;
use Illuminate\Console\Command;

class GenerateAgendaCommand extends Command
{
    protected $signature = 'agenda:generate';

    protected $description = 'Generate agendas for meetings whose minutes_before threshold has passed';

    public function handle(): int
    {
        $events = CalendarEvent::query()
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours(24))
            ->whereDoesntHave('agendas')
            ->with(['source.user.teams.notificationSettings'])
            ->get()
            ->filter(function (CalendarEvent $event) {
                $team = $event->source?->user?->teams->first();
                $setting = $team?->notificationSettings
                    ->where('event_type', 'meeting_agenda')
                    ->where('notifiable_type', TelegramChatRegistration::class)
                    ->first();
                $minutesBefore = $setting?->minutes_before ?? 60;

                return $event->starts_at->subMinutes($minutesBefore)->isPast();
            });

        foreach ($events as $event) {
            GenerateAgendaJob::dispatch($event);
        }

        $this->info("Dispatched agenda generation for {$events->count()} event(s).");

        return self::SUCCESS;
    }
}
