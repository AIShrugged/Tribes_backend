<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingSummaryTemplate extends Model
{
    protected $guarded = [];

    public const array DEFAULT_SECTIONS = [
        'key_points', 'decisions', 'tasks', 'commitments', 'repeated_discussions',
    ];

    protected $casts = [
        'sections' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isSectionEnabled(string $section): bool
    {
        return in_array($section, $this->sections, true);
    }
}
