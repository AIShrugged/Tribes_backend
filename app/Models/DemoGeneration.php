<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoGeneration extends Model
{
    protected $guarded = [];

    protected $casts = [
        'params'       => 'array',
        'data'         => 'array',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isGenerating(): bool
    {
        return $this->status === 'generating';
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, ['pending', 'generating']);
    }

    public function updateProgress(string $stepLabel, int $percent): void
    {
        $this->update([
            'status'             => 'generating',
            'current_step_label' => $stepLabel,
            'progress_percent'   => $percent,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error'  => $error,
        ]);
    }

    public function markReady(): void
    {
        $this->update([
            'status'             => 'ready',
            'progress_percent'   => 100,
            'current_step_label' => 'Готово!',
            'completed_at'       => now(),
        ]);
    }
}
