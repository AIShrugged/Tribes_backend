<?php

namespace App\Console\Commands;

use App\Enums\AgendaStatus;
use App\Jobs\SendAgendaNotificationsJob;
use App\Models\MeetingAgenda;
use Illuminate\Console\Command;

class SendAgendaCommand extends Command
{
    protected $signature = 'agenda:send';

    protected $description = 'Fallback: send agenda notifications for any DONE unsent agendas from upcoming meetings';

    public function handle(): int
    {
        $agendas = MeetingAgenda::query()
            ->where('status', AgendaStatus::DONE)
            ->whereNull('sent_at')
            ->whereHas('calendarEvent', fn ($q) => $q->where('starts_at', '>', now()->subHours(3)))
            ->with(['calendarEvent'])
            ->get();

        $dispatched = 0;
        $eventIds = [];

        foreach ($agendas as $agenda) {
            $calendarEvent = $agenda->calendarEvent;
            if (! $calendarEvent) {
                continue;
            }

            if (in_array($calendarEvent->id, $eventIds, true)) {
                continue;
            }

            SendAgendaNotificationsJob::dispatch($calendarEvent);
            $eventIds[] = $calendarEvent->id;
            $dispatched++;
        }

        $this->info("Dispatched agenda notifications for {$dispatched} event(s).");

        return self::SUCCESS;
    }
}
