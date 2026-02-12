<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramUser extends Model
{
    protected $primaryKey = 'telegram_user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = [];

    protected $casts = [
        'telegram_user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get email from linked User account
     */
    public function getEmail(): ?string
    {
        return $this->user?->email;
    }

    /**
     * Check if user has linked account
     */
    public function hasLinkedUser(): bool
    {
        return $this->user_id !== null;
    }

    public static function findOrCreateByTelegramId(int $telegramUserId, ?string $username = null): self
    {
        return self::firstOrCreate(
            ['telegram_user_id' => $telegramUserId],
            ['telegram_username' => $username]
        );
    }
}
