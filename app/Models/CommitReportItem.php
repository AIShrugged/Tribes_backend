<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitReportItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'architect_comment' => 'array',
            'related_issues' => 'array',
            'unmatched' => 'boolean',
            'matched_issue_id' => 'integer',
            'position' => 'integer',
            'dropped_at' => 'datetime',
        ];
    }

    public function commitReport(): BelongsTo
    {
        return $this->belongsTo(CommitReport::class);
    }

    public function matchedIssue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'matched_issue_id');
    }

    /** A real, validated issue id is the single source of truth for "matched". */
    public function getMatchedAttribute(): bool
    {
        return $this->matched_issue_id !== null;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('review_status', 'pending');
    }

    public function scopeForReport(Builder $query, int $commitReportId): Builder
    {
        return $query->where('commit_report_id', $commitReportId);
    }
}
