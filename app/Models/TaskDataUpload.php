<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TaskDataUpload extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issues_created' => 'integer',
            'issues_updated' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function issues(): MorphMany
    {
        return $this->morphMany(Issue::class, 'sourceable');
    }
}
