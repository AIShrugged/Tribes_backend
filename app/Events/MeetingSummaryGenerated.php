<?php

namespace App\Events;

use App\Models\MeetingSummary;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingSummaryGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MeetingSummary $summary,
    ) {}
}
