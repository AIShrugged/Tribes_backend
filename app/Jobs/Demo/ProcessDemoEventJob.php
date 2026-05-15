<?php

namespace App\Jobs\Demo;

use App\Models\CalendarEvent;
use App\Models\DemoGeneration;
use App\Models\Team;
use App\Models\User;
use App\Services\Followup\FollowupService;
use App\Services\Insight\InsightEvolutionService;
use App\Services\Insight\InsightExtractionService;
use App\Services\Meeting\MeetingSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDemoEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(
        private readonly int $generationId,
        private readonly int $eventId,
    ) {
    }

    public function handle(
        InsightExtractionService $insightExtraction,
        InsightEvolutionService  $insightEvolution,
        MeetingSummaryService    $summaryService,
        FollowupService          $followupService,
    ): void {
        $generation = DemoGeneration::findOrFail($this->generationId);
        $event      = CalendarEvent::findOrFail($this->eventId);

        Log::info('ProcessDemoEventJob: starting pipeline for event', ['event_id' => $this->eventId]);

        try {
            // --- 1. Extract Insight items ---
            $sources = $insightExtraction->extract($event);

            foreach ($sources as $source) {
                $insightEvolution->evolveFromSource($source);
            }

            // --- 2. Meeting Summary ---
            $summaryService->generate($event);

            // --- 3. Followups for each demo participant ---
            $team = $this->findTeamForEvent($generation);
            if ($team) {
                $this->generateFollowups($event, $team, $generation, $followupService);
            }

            Log::info('ProcessDemoEventJob: pipeline completed', ['event_id' => $this->eventId]);
        } catch (\Throwable $e) {
            Log::error('ProcessDemoEventJob: pipeline step failed', [
                'event_id' => $this->eventId,
                'error'    => $e->getMessage(),
            ]);
            // Don't mark generation as failed — other events may still complete
        }
    }

    /**
     * Find the team this event belongs to using demo_generations.data mapping.
     */
    private function findTeamForEvent(DemoGeneration $generation): ?Team
    {
        $data = $generation->data ?? [];
        foreach ($data['teams'] ?? [] as $teamData) {
            foreach ($teamData['events'] ?? [] as $eventData) {
                if ($eventData['event_id'] === $this->eventId) {
                    return Team::find($teamData['team_id']);
                }
            }
        }
        return null;
    }

    /**
     * Generate followup for each demo user that participated in this event.
     */
    private function generateFollowups(
        CalendarEvent   $event,
        Team            $team,
        DemoGeneration  $generation,
        FollowupService $followupService,
    ): void {
        $data = $generation->data ?? [];

        // Find user_ids for this event's team
        $teamUserIds = [];
        foreach ($data['teams'] ?? [] as $teamData) {
            if ($teamData['team_id'] === $team->id) {
                $teamUserIds = $teamData['user_ids'] ?? [];
                break;
            }
        }

        if (empty($teamUserIds)) {
            return;
        }

        foreach ($teamUserIds as $userId) {
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            try {
                $followupService->generate($event, $team, $user);
            } catch (\Throwable $e) {
                Log::warning('ProcessDemoEventJob: followup generation failed for user', [
                    'user_id'  => $userId,
                    'event_id' => $this->eventId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
}
