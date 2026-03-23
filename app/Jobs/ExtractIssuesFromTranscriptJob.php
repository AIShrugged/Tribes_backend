<?php

namespace App\Jobs;

use App\Events\IssuesExtracted;
use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\IssueExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractIssuesFromTranscriptJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CalendarEvent $calendarEvent,
        public Team $team,
        public User $user,
    ) {}

    public function handle(IssueExtractionService $service): void
    {
        $issues = $service->extract($this->calendarEvent, $this->team, $this->user);

        if ($issues->isNotEmpty()) {
            IssuesExtracted::dispatch($issues, $this->team, $this->user);
        }
    }
}
