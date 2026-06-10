<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Staging row for human pre-moderation of AI-extracted tasks/decisions from a MANUAL upload.
 *
 * One logical row per source (CalendarEvent for transcripts, TaskDataUpload for files).
 * Created eagerly by a single gated writer (controller/job) so the async producers only
 * ever UPDATE it under a row lock — there is no firstOrCreate race.
 *
 * `plan` shape (see PREMODERATION_PLAN.md §3.3):
 *   {
 *     "issues":   { "items": [...], "decisions": [...], "existing_snapshots": [...] },
 *     "decisions":{ "items": [...] },
 *     "review":   { "review_id": int|null }
 *   }
 */
class ExtractionPlan extends Model
{
    protected $guarded = [];

    public const STATUS_COLLECTING = 'collecting';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'expected_sections' => 'array',
            'section_status' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isCollecting(): bool
    {
        return $this->status === self::STATUS_COLLECTING;
    }

    public function isPendingReview(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
