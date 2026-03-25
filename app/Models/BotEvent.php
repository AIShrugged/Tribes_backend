<?php

namespace App\Models;

use App\Enums\BotEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type'        => BotEventType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
