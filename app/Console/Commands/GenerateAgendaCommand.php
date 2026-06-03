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
                    $user = $event->source?->user;
                    if (! $user) {
                        return false;
                    }

                    // Mirror the SENDER's team set (UserTeamsResolver::forOutboundNotification):
                    // real teams of the event's org + the default team only if it has settings.
                    $orgId = $event->source?->organization_id;
                    $teams = $user->teams
                        ->when($orgId, fn ($c) => $c->where('organization_id', $orgId))
                        ->filter(fn ($t) => ! $t->is_default || $t->notificationSettings->isNotEmpty());

                    // Generate early enough for the earliest-firing recipient (MIN lead across all
                    // relevant meeting_agenda settings). Null minutes_before falls back to 60.
                    $minutesBefore = $teams
                        ->flatMap(fn ($t) => $t->notificationSettings
                            ->where('event_type', 'meeting_agenda')
                            ->where('notifiable_type', TelegramChatRegistration::class)
                            ->map(fn ($setting) => $setting->minutes_before ?? 60))
                        ->min() ?? 60;

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
