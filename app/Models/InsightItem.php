<?php

namespace App\Models;

use App\Enums\InsightCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsightItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'category'    => InsightCategory::class,
        'confidence'  => 'float',
        'is_archived' => 'boolean',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(InsightSource::class, 'insight_source_id');
    }
}
