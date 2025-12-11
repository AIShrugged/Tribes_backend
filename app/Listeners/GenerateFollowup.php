<?php

namespace App\Listeners;

use App\Enums\FollowupScope;
use App\Enums\FollowupType;
use App\Events\TranscriptParsed;
use App\Services\Followup\FollowupService;

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
        app(FollowupService::class)->generate(
            $event->calendarEvent,
            FollowupScope::SHARED->value,
        );
    }
}
