<?php

namespace App\Jobs;

use App\Models\MeetingSummary;
use App\Services\Agenda\MeetingSeriesStateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateMeetingSeriesStateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public MeetingSummary $summary,
    ) {}

    public function handle(MeetingSeriesStateService $service): void
    {
        $this->summary->loadMissing('calendarEvent.sources.user.organizations');

        $service->updateAfterMeeting(
            $this->summary->calendarEvent,
            $this->summary,
        );
    }
}
