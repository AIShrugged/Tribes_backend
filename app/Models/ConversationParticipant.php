<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ConversationParticipant extends Model
{
    protected $fillable = [
        'conversation_id',
        'participantable_type',
        'participantable_id',
        'role',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function participantable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }
}
