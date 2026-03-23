<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Jobs\ExtractIssuesFromTranscriptJob;
use App\Jobs\GenerateFollowupJob;
use Illuminate\Support\Facades\Log;

class GenerateFollowup
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(TranscriptParsed $event): void
    {
        $calendarEvent = $event->calendarEvent;
        $user = $calendarEvent->source->user;

        // Получить все команды пользователя
        $teams = $user->teams;

        // Если нет команд - не создавать followup'ы
        if ($teams->isEmpty()) {
            Log::info("User {$user->id} has no teams, skipping followup generation");
            return;
        }

        // Запустить Job для каждой команды параллельно
        foreach ($teams as $team) {
            GenerateFollowupJob::dispatch($calendarEvent, $team, $user);
            ExtractIssuesFromTranscriptJob::dispatch($calendarEvent, $team, $user);
        }
    }
}
