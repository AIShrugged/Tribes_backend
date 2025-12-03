<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Followup extends Model
{
    protected $guarded = [];

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }

    public function scopeOwned(Builder $query, int $userId): Builder
    {
        return $query->whereHas('calendarEvent.source', function (Builder $query) use ($userId) {
            $query->where('user_id', $userId);
        });
    }
}
