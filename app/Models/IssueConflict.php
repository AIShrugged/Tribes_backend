<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueConflict extends Model
{
    public const FIELD_REQUIREMENTS = 'requirements';
    public const FIELD_DUE_DATE = 'due_date';
    public const FIELD_ASSIGNEE = 'assignee';

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';

    public const FIELDS = [
        self::FIELD_REQUIREMENTS,
        self::FIELD_DUE_DATE,
        self::FIELD_ASSIGNEE,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function detectedInCalendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'detected_in_calendar_event_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
