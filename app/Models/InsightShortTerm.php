<?php

namespace App\Models;

use App\Enums\InsightContextType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsightShortTerm extends Model
{
    protected $table = 'insight_short_term';

    protected $guarded = [];

    protected $casts = [
        'context_type' => InsightContextType::class,
        'content'      => 'array',
        'expires_at'   => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(InsightSource::class, 'insight_source_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeForFocus(Builder $query, int $profileId): Builder
    {
        return $query
            ->where('profile_id', $profileId)
            ->where('context_type', InsightContextType::USER_FOCUS);
    }

    public static function setFocus(int $profileId, string $focusText, ?string $deadline, ?Carbon $expiresAt): self
    {
        return static::updateOrCreate(
            ['profile_id' => $profileId, 'context_type' => InsightContextType::USER_FOCUS],
            ['content' => ['focus_text' => $focusText, 'deadline' => $deadline], 'expires_at' => $expiresAt],
        );
    }
}
