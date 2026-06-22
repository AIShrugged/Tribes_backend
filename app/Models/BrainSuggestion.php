<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A human-in-the-loop action proposed by the second brain. See the
 * brain_suggestions migration and App\Services\SecondBrain\SuggestionApplier.
 */
class BrainSuggestion extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_EXPIRED = 'expired';

    /** Action keys the brain may propose (must have a handler in SuggestionApplier). */
    public const KEY_CREATE_ISSUE = 'create_issue';
    public const KEY_UPDATE_TASK_STATUS = 'update_task_status';
    public const KEY_ADD_COMMENT = 'add_comment';

    public const KEYS = [
        self::KEY_CREATE_ISSUE,
        self::KEY_UPDATE_TASK_STATUS,
        self::KEY_ADD_COMMENT,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'evidence' => 'array',
            'applied_result' => 'array',
            'payload_version' => 'integer',
            'confidence' => 'integer',
            'resolved_at' => 'datetime',
            'applied_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function subjectable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
