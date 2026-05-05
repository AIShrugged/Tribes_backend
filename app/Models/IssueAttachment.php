<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueAttachment extends Model
{
    protected $guarded = [];

    public const CREATED_AT = 'uploaded_at';

    public const UPDATED_AT = null;

    /** Pending uploads older than this are pruned by PruneOrphanAttachments. */
    public const ORPHAN_TTL_HOURS = 24;

    protected function casts(): array
    {
        return [
            'uploaded_at'         => 'datetime',
            'uploaded_by_user_id' => 'integer',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    public function task(): BelongsTo
    {
        return $this->issue();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function scopePending(Builder $query, string $token, int $userId): Builder
    {
        return $query
            ->whereNull('issue_id')
            ->where('upload_token', $token)
            ->where('uploaded_by_user_id', $userId)
            ->where('uploaded_at', '>=', now()->subHours(self::ORPHAN_TTL_HOURS));
    }
}
