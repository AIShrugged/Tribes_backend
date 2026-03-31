<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpcomingAgenda extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceCalendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'source_calendar_event_id');
    }
}
