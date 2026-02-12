<?php

namespace App\Console\Commands;

use App\Events\Insight\InsightItemsExtracted;
use App\Models\CalendarEvent;
use App\Services\Insight\InsightExtractionService;
use Illuminate\Console\Command;

class InsightBootstrap extends Command
{
    protected $signature = 'insight:bootstrap
                            {--limit= : Max number of events to process}
                            {--event= : Process a specific calendar event ID}';

    protected $description = 'Bootstrap Insight profiles from existing transcripts';

    public function handle(InsightExtractionService $extraction): int
    {
        if ($eventId = $this->option('event')) {
            $events = CalendarEvent::where('id', $eventId)->get();
        } else {
            $query = CalendarEvent::has('transcriptEntries');

            if ($limit = $this->option('limit')) {
                $query->limit((int) $limit);
            }

            $events = $query->oldest()->get();
        }

        if ($events->isEmpty()) {
            $this->warn('No events with transcripts found.');
            return self::SUCCESS;
        }

        $this->info("Processing {$events->count()} events...");
        $bar = $this->output->createProgressBar($events->count());
        $bar->start();

        $processed = 0;
        $skipped   = 0;

        foreach ($events as $event) {
            $sources = $extraction->extract($event);

            if (!empty($sources)) {
                InsightItemsExtracted::dispatch($event, $sources);
                $processed++;
            } else {
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Processed: {$processed}, Skipped: {$skipped}");
        $this->line('Profile evolution jobs dispatched to queue.');

        return self::SUCCESS;
    }
}
