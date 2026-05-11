<?php

namespace App\Listeners\Insight;

use App\Events\Insight\InsightItemsExtracted;
use App\Services\Insight\InsightRelationshipService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class UpdateInsightRelationshipsListener implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function handle(InsightItemsExtracted $event): void
    {
        $profileIds = collect($event->sources)->pluck('profile_id')->toArray();

        if (count($profileIds) < 2) {
            return;
        }

        try {
            app(InsightRelationshipService::class)->processFromEvent(
                $event->calendarEvent,
                $profileIds,
            );
        } catch (\Throwable $e) {
            Log::error('UpdateInsightRelationshipsListener: failed', [
                'calendar_event_id' => $event->calendarEvent->id,
                'error'             => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
