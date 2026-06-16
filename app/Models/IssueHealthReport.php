<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueHealthReport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start'  => 'date',
            'findings'      => 'array',
            'generated_at'  => 'datetime',
            'expires_at'    => 'datetime',
            'status'        => 'string',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopeLatestForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId)->orderByDesc('period_start');
    }
}
