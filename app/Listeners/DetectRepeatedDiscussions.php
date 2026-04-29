<?php

namespace App\Listeners;

use App\Events\MeetingSummaryGenerated;
use App\Services\Meeting\DetectRepeatedDiscussionsService;
use Illuminate\Support\Facades\Log;

class DetectRepeatedDiscussions
{
    public function __construct(
        private readonly DetectRepeatedDiscussionsService $service,
    ) {}

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

        $allMatches = [];

        foreach ($teams as $team) {
            try {
                $matches = $this->service->detect($summary, $team);
                $allMatches = array_merge($allMatches, $matches);
            } catch (\Throwable $e) {
                Log::error('DetectRepeatedDiscussions: detection failed', [
                    'calendar_event_id' => $calendarEvent->id,
                    'team_id' => $team->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $summary->update(['repeated_discussions' => $allMatches]);
    }
}
