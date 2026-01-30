<?php

namespace App\Jobs;

use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Followup\FollowupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateFollowupJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public CalendarEvent $calendarEvent,
        public Team $team,
        public User $user
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(FollowupService $service): void
    {
        $service->generate($this->calendarEvent, $this->team, $this->user);
    }
}