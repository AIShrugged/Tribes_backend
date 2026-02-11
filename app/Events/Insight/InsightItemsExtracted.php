<?php

namespace App\Events\Insight;

use App\Models\CalendarEvent;
use App\Models\InsightSource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InsightItemsExtracted
{
    use Dispatchable, SerializesModels;

    /**
     * @param  InsightSource[]  $sources  One per participant that was processed
     */
    public function __construct(
        public readonly CalendarEvent $calendarEvent,
        public readonly array $sources,
    ) {}
}
