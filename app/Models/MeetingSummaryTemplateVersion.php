<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable history record of a {@see MeetingSummaryTemplate} before it was updated.
 * Written by {@see \App\Http\Controllers\API\v1\MeetingSummaryTemplateController::upsert}
 * each time the template changes, so a team can roll back or audit prompt changes.
 */
class MeetingSummaryTemplateVersion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'sections'         => 'array',
        'visible_sections' => 'array',
        'created_at'       => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MeetingSummaryTemplate::class, 'template_id');
    }
}
