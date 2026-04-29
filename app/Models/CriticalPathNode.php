<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CriticalPathNode extends Model
{
    protected $fillable = [
        'graph_id',
        'issue_id',
        'node_type',
        'duration_days',
        'early_start',
        'early_finish',
        'late_start',
        'late_finish',
        'slack',
        'is_critical',
    ];

    protected $casts = [
        'duration_days' => 'float',
        'early_start' => 'float',
        'early_finish' => 'float',
        'late_start' => 'float',
        'late_finish' => 'float',
        'slack' => 'float',
        'is_critical' => 'boolean',
    ];

    public function graph(): BelongsTo
    {
        return $this->belongsTo(CriticalPathGraph::class, 'graph_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(CriticalPathEdge::class, 'from_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(CriticalPathEdge::class, 'to_node_id');
    }

    public function isIssueNode(): bool
    {
        return $this->node_type === 'issue';
    }
}
