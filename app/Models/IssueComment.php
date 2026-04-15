<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IssueComment extends Model
{
    protected $guarded = [];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(IssueComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(IssueComment::class, 'parent_id')->with('user')->orderBy('created_at');
    }
}
