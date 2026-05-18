<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Jobs\ExtractIssuesFromTranscriptJob;
use App\Jobs\GenerateFollowupJob;
use App\Services\CalendarEventOrganizationResolver;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateFollowup implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function handle(TranscriptParsed $event): void
    {
        $calendarEvent = $event->calendarEvent;
        $user = $calendarEvent->source->user;

        $orgId = $calendarEvent->source?->organization_id;
        $teams = $orgId
            ? $user->teams()->where('organization_id', $orgId)->get()
            : $user->teams;

        if ($teams->isEmpty()) {
            Log::info("User {$user->id} has no teams, skipping followup generation");
            return;
        }

        foreach ($teams as $team) {
            GenerateFollowupJob::dispatch($calendarEvent, $team, $user);
        }

        $resolved = app(CalendarEventOrganizationResolver::class)->resolve($calendarEvent);

        if ($resolved) {
            ExtractIssuesFromTranscriptJob::dispatch($calendarEvent, $resolved['team'], $resolved['user']);
        } else {
            Log::info("Could not resolve organization for calendar event {$calendarEvent->id}, skipping issue extraction");
        }
    }
}
