<?php

namespace App\Listeners\Insight;

use App\Events\Insight\InsightItemsExtracted;
use App\Events\TranscriptParsed;
use App\Services\Insight\InsightExtractionService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExtractInsightItemsListener implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

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
