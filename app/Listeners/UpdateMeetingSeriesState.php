<?php

namespace App\Listeners;

use App\Events\MeetingSummaryGenerated;
use App\Jobs\UpdateMeetingSeriesStateJob;

class UpdateMeetingSeriesState
{
    public function handle(MeetingSummaryGenerated $event): void
    {
        UpdateMeetingSeriesStateJob::dispatch($event->summary);
    }
}
