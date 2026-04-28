<?php

namespace App\Listeners;

use App\Events\MeetingSummaryGenerated;
use App\Jobs\DetectRepeatedDiscussionsJob;
use Illuminate\Support\Facades\Log;

class DetectRepeatedDiscussions
{
    public function handle(MeetingSummaryGenerated $event): void
    {
        $summary = $event->summary;
        $calendarEvent = $summary->calendarEvent;

        if (! $calendarEvent?->source?->user) {
            return;
        }

        $teams = $calendarEvent->source->user->teams;

        if ($teams->isEmpty()) {
            Log::info('DetectRepeatedDiscussions: no teams found', [
                'calendar_event_id' => $calendarEvent->id,
            ]);

            return;
        }

        foreach ($teams as $team) {
            DetectRepeatedDiscussionsJob::dispatch($summary, $team);
        }
    }
}
