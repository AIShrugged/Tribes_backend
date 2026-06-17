<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommitReport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'items' => 'array',
            'commit_shas' => 'array',
            'commit_count' => 'integer',
            'total_in_window' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function generatedByRun(): BelongsTo
    {
        return $this->belongsTo(AgentTaskRun::class, 'generated_by_agent_task_run_id');
    }

    /** Child rows: one per added/fixed item (matching + deep-review authority). */
    public function reportItems(): HasMany
    {
        return $this->hasMany(CommitReportItem::class);
    }

    /** Latest-first reports for a repo/branch (includes empties). */
    public function scopeForRepoBranch(Builder $query, string $repo, string $branch): Builder
    {
        return $query->where('repo', $repo)->where('branch', $branch)
            ->orderByDesc('period_end')
            ->orderByDesc('period_start')
            ->orderByDesc('id');
    }

    /** Non-empty timeline (future UI: skip "no changes" rows). */
    public function scopeMeaningful(Builder $query): Builder
    {
        return $query->where('status', '!=', 'empty')->where('commit_count', '>', 0);
    }
}
