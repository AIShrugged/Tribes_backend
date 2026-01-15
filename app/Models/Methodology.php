<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Methodology extends Model
{
    protected $guarded = [];

    public function scopeOwned(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $query) use ($userId) {
            $query->where('user_id', $userId)
                ->orWhere('is_default', true);
        });
    }

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }

    public function isDefault(): bool
    {
        return $this->is_default;
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function getDefault(): self
    {
        return self::query()->where('is_default', true)->firstOrFail();
    }
}
