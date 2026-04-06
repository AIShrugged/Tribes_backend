<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Models\CalendarEvent;
use App\Services\Agenda\AgendaService;
use Illuminate\Console\Command;

class GenerateMissingAgendasCommand extends Command
{
    protected $signature = 'agenda:generate-missing {--from= : Only process events that start on or after this date (YYYY-MM-DD)} {--date= : Alias for --from}';

    protected $description = 'Backfill agendas by re-running agenda generation for all calendar events';

    public function handle(AgendaService $agendaService): int
    {
        $query = CalendarEvent::query();

        $from = $this->option('from') ?: $this->option('date');

        if ($from) {
            $query->whereDate('starts_at', '>=', Carbon::parse($from)->toDateString());
        }

        $events = $query
            ->orderBy('starts_at')
            ->get();

        foreach ($events as $event) {
            $agendaService->generateForEvent($event);
        }

        $suffix = $from ? " from {$from}" : '';
        $this->info("Processed {$events->count()} event(s) for agenda backfill{$suffix}.");

        return self::SUCCESS;
    }
}
