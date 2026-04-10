<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingSummary extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'key_points'  => 'array',
            'decisions'   => 'array',
            'commitments' => 'array',
        ];
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }
}
