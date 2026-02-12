<?php

namespace App\Models;

use App\Enums\InsightCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsightProfileHistory extends Model
{
    protected $table = 'insight_profile_history';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'category'   => InsightCategory::class,
        'content'    => 'array',
        'created_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(InsightProfile::class, 'insight_profile_id');
    }
}
