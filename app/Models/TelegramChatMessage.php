<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TelegramChatMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'telegram_chat_id' => 'integer',
        'message_thread_id' => 'integer',
        'coalesced_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'telegram_user_id', 'telegram_user_id');
    }

    public function issues(): MorphMany
    {
        return $this->morphMany(Issue::class, 'sourceable');
    }

    public function tasks(): MorphMany
    {
        return $this->issues();
    }
}
