<?php

namespace App\Models;

use App\Enums\InsightContextType;
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
}
