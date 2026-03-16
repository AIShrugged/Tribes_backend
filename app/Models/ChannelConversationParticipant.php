<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelConversationParticipant extends Model
{
    protected $fillable = [
        'conversation_id',
        'channel_identity_id',
        'joined_at',
        'last_message_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChannelConversation::class, 'conversation_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(ChannelIdentity::class, 'channel_identity_id');
    }
}
