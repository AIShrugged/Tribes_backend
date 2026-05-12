<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgendaTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sections' => 'array',
    ];

    public const array SECTIONS_PRE_MEETING = [
        'meeting_goal',
        'discussion_topics',
        'main_problem',
        'prev_topics',
        'commitments_check',
        'tasks_between',
        'backlog_stats',
        'tg_topics',
    ];

    public const array SECTIONS_UPCOMING = [
        'next_meeting_context',
        'follow_up_items',
        'open_questions',
        'focus_areas',
    ];

    public const array DEFAULT_SECTIONS = [
        ...self::SECTIONS_PRE_MEETING,
        ...self::SECTIONS_UPCOMING,
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function orderedSections(): array
    {
        return $this->sections ?: self::DEFAULT_SECTIONS;
    }

    public static function defaultOrderedSections(): array
    {
        return self::DEFAULT_SECTIONS;
    }
}
