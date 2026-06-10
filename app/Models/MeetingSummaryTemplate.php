<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingSummaryTemplate extends Model
{
    protected $guarded = [];

    public const array DEFAULT_SECTIONS = [
        'key_points', 'decisions', 'tasks', 'commitments', 'repeated_discussions', 'conflicts',
    ];

    public const array DEFAULT_VISIBLE_SECTIONS = ['key_points'];

    /**
     * Placeholders supported inside {@see $prompt_override}; substituted at runtime by
     * {@see \App\Services\Meeting\MeetingSummaryService::buildPrompt}.
     */
    public const array PROMPT_PLACEHOLDERS = ['{transcript}', '{meeting_date}', '{end_of_week}', '{example}'];

    protected $casts = [
        'sections'         => 'array',
        'visible_sections' => 'array',
        'version'          => 'integer',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MeetingSummaryTemplateVersion::class, 'template_id')->orderByDesc('version');
    }

    public function isSectionEnabled(string $section): bool
    {
        return in_array($section, $this->sections, true);
    }
}
