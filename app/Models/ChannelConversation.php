<?php

namespace App\Models;

use App\Enums\ConversationChannelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ChannelConversation extends Model
{
    protected $fillable = [
        'channel_type',
        'conversation_key',
        'user_id',
        'chat_id',
        'telegram_chat_id',
        'message_thread_id',
        'title',
        'latest_message_at',
    ];

    protected $casts = [
        'channel_type' => ConversationChannelType::class,
        'telegram_chat_id' => 'integer',
        'message_thread_id' => 'integer',
        'latest_message_at' => 'datetime',
    ];

    public static function keyForChat(int $chatId): string
    {
        return 'web_chat:'.$chatId;
    }

    public static function keyForTelegram(int $chatId, ?int $messageThreadId = null): string
    {
        return sprintf('telegram:%s:%s', $chatId, $messageThreadId ?? 'root');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChannelMessage::class, 'conversation_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChannelConversationParticipant::class, 'conversation_id');
    }

    public function identities(): HasManyThrough
    {
        return $this->hasManyThrough(
            ChannelIdentity::class,
            ChannelConversationParticipant::class,
            'conversation_id',
            'id',
            'id',
            'channel_identity_id'
        );
    }
}
