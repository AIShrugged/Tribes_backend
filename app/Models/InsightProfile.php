<?php

namespace App\Models;

use App\Enums\InsightCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsightProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'category'        => InsightCategory::class,
        'content'         => 'array',
        'last_updated_at' => 'datetime',
    ];

    public function history(): HasMany
    {
        return $this->hasMany(InsightProfileHistory::class);
    }

    public function isReady(): bool
    {
        return $this->source_count >= 3;
    }
}
