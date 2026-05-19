<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskDigest extends Model
{
    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'content' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopeDaily(Builder $query): Builder
    {
        return $query->where('period_type', self::PERIOD_DAILY);
    }

    public function scopeWeekly(Builder $query): Builder
    {
        return $query->where('period_type', self::PERIOD_WEEKLY);
    }
}
