<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationCompactionSnapshot extends Model
{
    protected $fillable = [
        'conversation_key',
        'keep_recent_messages',
        'message_count',
        'last_message_id',
        'summary',
    ];
}
