<?php

namespace App\Listeners;

use App\Events\MeetingSummaryGenerated;
use App\Services\Meeting\DetectRepeatedDiscussionsService;
use App\Services\UserTeamsResolver;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DetectRepeatedDiscussions implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

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

        $user = $calendarEvent->source->user;
        $orgId = $calendarEvent->source?->organization_id;
        // Data-processing pipeline: prefer real teams, fall back to default.
        $teams = app(UserTeamsResolver::class)->forPipelineTrigger($user, $orgId);

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
