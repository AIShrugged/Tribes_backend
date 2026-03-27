<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingReview extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score'                      => 'decimal:2',
            'score_breakdown'            => 'array',
            'suggestions'                => 'array',
            'agenda_analysis'            => 'array',
            'participation'              => 'array',
            'previous_suggestions_check' => 'array',
        ];
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }
}
