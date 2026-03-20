<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramChatRegistration extends Model
{
    protected $fillable = [
        'channel_conversation_id',
        'telegram_chat_id',
        'message_thread_id',
        'chat_type',
        'chat_title',
        'attach_code',
        'organization_id',
        'team_id',
        'attach_requested_by_user_id',
        'bound_by_user_id',
        'attach_code_issued_at',
        'attach_code_expires_at',
        'attach_code_used_at',
        'bound_at',
    ];

    protected $casts = [
        'telegram_chat_id' => 'integer',
        'message_thread_id' => 'integer',
        'attach_code_issued_at' => 'datetime',
        'attach_code_expires_at' => 'datetime',
        'attach_code_used_at' => 'datetime',
        'bound_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChannelConversation::class, 'channel_conversation_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attach_requested_by_user_id');
    }

    public function boundBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bound_by_user_id');
    }
}
