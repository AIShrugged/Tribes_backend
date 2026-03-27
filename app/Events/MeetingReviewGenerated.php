<?php

namespace App\Events;

use App\Models\MeetingReview;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingReviewGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MeetingReview $review,
    ) {}
}
