<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Jobs\GenerateUpcomingAgendaJob;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

class GenerateUpcomingAgenda implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function handle(TranscriptParsed $event): void
    {
        GenerateUpcomingAgendaJob::dispatch($event->calendarEvent);
    }
}
