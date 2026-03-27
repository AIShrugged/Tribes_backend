<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Jobs\ExtractIssuesFromTranscriptJob;
use App\Jobs\GenerateFollowupJob;
use App\Services\CalendarEventOrganizationResolver;
use Illuminate\Support\Facades\Log;

class GenerateFollowup
{
    public function __construct(
        private readonly CalendarEventOrganizationResolver $organizationResolver,
    ) {}

    public function handle(TranscriptParsed $event): void
    {
        $calendarEvent = $event->calendarEvent;
        $user = $calendarEvent->source->user;

        $teams = $user->teams;

        if ($teams->isEmpty()) {
            Log::info("User {$user->id} has no teams, skipping followup generation");
            return;
        }

        // Followups — для каждой команды пользователя
        foreach ($teams as $team) {
            GenerateFollowupJob::dispatch($calendarEvent, $team, $user);
        }

        // Issues — один раз, для организации/команды определённой по участникам встречи
        $resolved = $this->organizationResolver->resolve($calendarEvent);

        if ($resolved) {
            ExtractIssuesFromTranscriptJob::dispatch($calendarEvent, $resolved['team'], $resolved['user']);
        } else {
            Log::info("Could not resolve organization for calendar event {$calendarEvent->id}, skipping issue extraction");
        }
    }
}
