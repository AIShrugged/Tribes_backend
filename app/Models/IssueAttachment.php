<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueAttachment extends Model
{
    protected $guarded = [];

    public const CREATED_AT = 'uploaded_at';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
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
}
