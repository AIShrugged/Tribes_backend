<?php

namespace App\Listeners\Insight;

use App\Events\Insight\InsightItemsExtracted;
use App\Services\Insight\InsightRelationshipService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class UpdateInsightRelationshipsListener implements ShouldQueue
{
    public string $queue = 'default';

    public function handle(InsightItemsExtracted $event): void
    {
        $emails = collect($event->sources)->pluck('email')->toArray();

        if (count($emails) < 2) {
            return;
        }

        try {
            app(InsightRelationshipService::class)->processFromEvent(
                $event->calendarEvent,
                $emails,
            );
        } catch (\Throwable $e) {
            Log::error('UpdateInsightRelationshipsListener: failed', [
                'calendar_event_id' => $event->calendarEvent->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }
}
