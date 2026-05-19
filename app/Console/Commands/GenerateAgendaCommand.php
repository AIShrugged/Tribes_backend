<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAgendaJob;
use App\Models\CalendarEvent;
use App\Models\TelegramChatRegistration;
use Illuminate\Console\Command;

class GenerateAgendaCommand extends Command
{
    protected $signature = 'agenda:generate {--all-today : Force-generate for ALL today\'s meetings, bypassing minutes_before threshold}';

    protected $description = 'Generate agendas for meetings whose minutes_before threshold has passed (or all today with --all-today)';

    public function handle(): int
    {
        $allToday = (bool) $this->option('all-today');

        $query = CalendarEvent::query()
            ->where('starts_at', '>', now())
            ->whereDoesntHave('agendas')
            ->with(['source.user.teams.notificationSettings']);

        if ($allToday) {
            // Pre-generate everything before end of today (server TZ).
            $query->where('starts_at', '<=', now()->endOfDay());
            $events = $query->get();
        } else {
            // Default behaviour: only meetings whose minutes_before threshold has passed.
            $events = $query
                ->where('starts_at', '<=', now()->addHours(24))
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
        }

        foreach ($events as $event) {
            GenerateAgendaJob::dispatch($event);
        }

        $this->info("Dispatched agenda generation for {$events->count()} event(s)" . ($allToday ? ' (forced — all today)' : '') . '.');

        return self::SUCCESS;
    }
}
