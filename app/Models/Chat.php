<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'team_id',
        'methodology_id',
        'title',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function methodology(): BelongsTo
    {
        return $this->belongsTo(Methodology::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
