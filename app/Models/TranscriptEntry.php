<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptEntry extends Model
{
    protected $guarded = [];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
