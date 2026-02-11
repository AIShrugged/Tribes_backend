<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TelegramUser extends Model
{
    protected $guarded = [];

    protected $casts = [
        'telegram_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function memory(): HasOne
    {
        return $this->hasOne(UserMemory::class);
    }

    /**
     * Get memory text for this user
     */
    public function getMemoryText(): ?string
    {
        return $this->memory?->text;
    }

    /**
     * Update or create memory for this user
     */
    public function updateMemory(string $text): UserMemory
    {
        return $this->memory()->updateOrCreate(
            ['telegram_user_id' => $this->id],
            ['text' => $text]
        );
    }

    public static function findOrCreateByTelegramId(int $telegramId, ?string $username = null): self
    {
        return self::firstOrCreate(
            ['telegram_id' => $telegramId],
            ['telegram_username' => $username]
        );
    }
}