<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingTaskReviewItem extends Model
{
    protected $fillable = [
        'meeting_task_review_id',
        'issue_id',
        'progress',
        'confidence',
        'notes',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(MeetingTaskReview::class, 'meeting_task_review_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}
