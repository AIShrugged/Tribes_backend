<?php

namespace App\Jobs;

use App\Models\CalendarEvent;
use App\Services\Issue\MeetingTaskReviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateMeetingTaskReviewJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly CalendarEvent $calendarEvent,
        private readonly int $organizationId,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(MeetingTaskReviewService $service): void
    {
        $service->generate($this->calendarEvent, $this->organizationId);
    }
}
