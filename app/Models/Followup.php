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
            // Фоллоуапы пользователя
            $q->where('user_id', $userId)
                // ИЛИ фоллоуапы команд, где пользователь - менеджер организации
                ->orWhereHas('team.organization.users', function (Builder $query) use ($userId) {
                    $query->where('users.id', $userId)
                        ->where('organization_user.role', 'manager');
                });
        });
    }
}
