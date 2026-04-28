<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingSummary extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'key_points'           => 'array',
            'decisions'            => 'array',
            'commitments'          => 'array',
            'repeated_discussions' => 'array',
        ];
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class, 'summary_id');
    }
}
