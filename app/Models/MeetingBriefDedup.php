<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Atomic dedup marker for pre-meeting briefs.
 *
 * Use via firstOrCreate or create-with-unique-catch. Replaces cache-based dedup
 * that was prone to Redis restart races.
 */
class MeetingBriefDedup extends Model
{
    protected $table = 'meeting_brief_dedup';

    protected $guarded = [];

    public const KIND_GROUP = 'group';
    public const KIND_PERSONAL = 'personal';

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }
}
