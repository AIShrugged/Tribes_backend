<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsightSource extends Model
{
    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InsightItem::class);
    }

    public function shortTermMemories(): HasMany
    {
        return $this->hasMany(InsightShortTerm::class);
    }
}
