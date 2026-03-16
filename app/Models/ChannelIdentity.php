<?php

namespace App\Models;

use App\Enums\ConversationChannelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelIdentity extends Model
{
    protected $fillable = [
        'channel_type',
        'external_id',
        'user_id',
        'display_name',
        'username',
        'metadata',
    ];

    protected $casts = [
        'channel_type' => ConversationChannelType::class,
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChannelMessage::class, 'author_identity_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ChannelConversationParticipant::class, 'channel_identity_id');
    }
}
