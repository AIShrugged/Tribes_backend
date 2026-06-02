<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptUpload extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'transcript_entries_count' => 'integer',
            'participants_count'       => 'integer',
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

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }

    /**
     * Org-wide + own-uploader visibility. NO team clause — manual-upload meetings
     * are org-scoped (the event's team is heuristic/async and unknown at upload time).
     * Explicit where-builder, no relation traversal.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $orgIds = $user->organizations()->pluck('organizations.id');

        return $query->where(function (Builder $q) use ($orgIds, $user) {
            if ($orgIds->isNotEmpty()) {
                $q->orWhereIn('organization_id', $orgIds);
            }
            $q->orWhere('user_id', $user->id); // ungated: always see your own uploads
        });
    }
}
