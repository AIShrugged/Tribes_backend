<?php

namespace App\Listeners;

use App\Events\MeetingSummaryGenerated;
use App\Jobs\UpdateMeetingSeriesStateJob;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

class UpdateMeetingSeriesState implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function handle(MeetingSummaryGenerated $event): void
    {
        UpdateMeetingSeriesStateJob::dispatch($event->summary);
    }
}
