<?php

namespace App\Listeners\Insight;

use App\Events\Insight\InsightItemsExtracted;
use App\Events\TranscriptParsed;
use App\Services\Insight\InsightExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ExtractInsightItemsListener implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(TranscriptParsed $event): void
    {
        $sources = app(InsightExtractionService::class)->extract($event->calendarEvent);

        if (empty($sources)) {
            Log::info('ExtractInsightItemsListener: no sources extracted', [
                'calendar_event_id' => $event->calendarEvent->id,
            ]);
            return;
        }

        InsightItemsExtracted::dispatch($event->calendarEvent, $sources);
    }
}
