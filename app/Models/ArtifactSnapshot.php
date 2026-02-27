<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtifactSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'chat_id',
        'last_event_id',
        'state_json',
        'created_at',
    ];

    protected $casts = [
        'state_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
