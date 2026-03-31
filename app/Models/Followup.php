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

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function methodology(): BelongsTo
    {
        return $this->belongsTo(Methodology::class);
    }

    public function scopeOwned(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            // Own follow-ups (created by this user)
            $q->where('user_id', $userId)
                // OR follow-ups from teams where user is a direct member
                ->orWhereHas('team.users', function (Builder $inner) use ($userId) {
                    $inner->where('users.id', $userId);
                })
                // OR follow-ups from org teams where user is a manager
                ->orWhereHas('team.organization.users', function (Builder $inner) use ($userId) {
                    $inner->where('users.id', $userId)
                        ->where('organization_user.role', 'manager');
                });
        });
    }
}
