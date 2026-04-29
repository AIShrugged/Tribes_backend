<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CriticalPathEdge extends Model
{
    protected $fillable = [
        'graph_id',
        'from_node_id',
        'to_node_id',
        'edge_type',
    ];

    public function graph(): BelongsTo
    {
        return $this->belongsTo(CriticalPathGraph::class, 'graph_id');
    }

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(CriticalPathNode::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(CriticalPathNode::class, 'to_node_id');
    }
}
