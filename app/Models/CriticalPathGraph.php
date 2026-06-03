<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CriticalPathGraph extends Model
{
    protected $fillable = [
        'organization_id',
        'team_id',
        'status',
        'computed_at',
        'last_notified_signature',
    ];

    protected $casts = [
        'computed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(CriticalPathNode::class, 'graph_id');
    }

    public function edges(): HasMany
    {
        return $this->hasMany(CriticalPathEdge::class, 'graph_id');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isComputing(): bool
    {
        return $this->status === 'computing';
    }
}
