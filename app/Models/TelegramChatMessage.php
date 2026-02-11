<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramChatMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'telegram_chat_id' => 'integer',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }
}
