<?php

namespace App\Console\Commands;

use App\Enums\AgendaStatus;
use App\Jobs\SendAgendaNotificationsJob;
use App\Models\CalendarEvent;
use App\Models\MeetingAgenda;
use Illuminate\Console\Command;

class SendAgendaCommand extends Command
{
    protected $signature = 'agenda:send';

    protected $description = 'Send agenda notifications for meetings starting in ~30 minutes';

    public function handle(): int
    {
        $eventIds = MeetingAgenda::query()
            ->where('status', AgendaStatus::DONE)
            ->whereNull('sent_at')
            ->where('send_scheduled_at', '<=', now())
            ->distinct()
            ->pluck('calendar_event_id');

        $events = CalendarEvent::whereIn('id', $eventIds)->get();

        foreach ($events as $event) {
            SendAgendaNotificationsJob::dispatch($event);
        }

        $this->info("Dispatched agenda notifications for {$events->count()} event(s).");

        return self::SUCCESS;
    }
}
