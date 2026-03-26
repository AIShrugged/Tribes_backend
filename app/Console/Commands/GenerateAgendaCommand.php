<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAgendaJob;
use App\Models\CalendarEvent;
use Illuminate\Console\Command;

class GenerateAgendaCommand extends Command
{
    protected $signature = 'agenda:generate';

    protected $description = 'Generate agendas for meetings starting in ~60 minutes';

    public function handle(): int
    {
        $events = CalendarEvent::query()
            ->whereBetween('starts_at', [
                now()->addMinutes(55),
                now()->addMinutes(65),
            ])
            ->whereDoesntHave('agendas')
            ->get();

        foreach ($events as $event) {
            GenerateAgendaJob::dispatch($event);
        }

        $this->info("Dispatched agenda generation for {$events->count()} event(s).");

        return self::SUCCESS;
    }
}
