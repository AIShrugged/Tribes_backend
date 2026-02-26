<?php

namespace App\Models;

use App\Enums\ChannelType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'channel_type',
        'external_id',
        'title',
        'status',
        'metadata',
    ];

    protected $casts = [
        'channel_type' => ChannelType::class,
        'metadata'     => 'array',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function owner(): ?ConversationParticipant
    {
        return $this->participants()->where('role', 'owner')->first();
    }

    public function isGroup(): bool
    {
        return $this->channel_type === ChannelType::TelegramGroup;
    }

    public function isTelegram(): bool
    {
        return in_array($this->channel_type, [ChannelType::TelegramPrivate, ChannelType::TelegramGroup]);
    }

    public function isWeb(): bool
    {
        return $this->channel_type === ChannelType::Web;
    }

    public function hasParticipant(Model $actor): bool
    {
        return $this->participants()
            ->where('participantable_type', $actor::class)
            ->where('participantable_id', $actor->getKey())
            ->exists();
    }

    public function isOwner(User $user): bool
    {
        return $this->participants()
            ->where('participantable_type', User::class)
            ->where('participantable_id', $user->getKey())
            ->where('role', 'owner')
            ->exists();
    }

    public function addParticipant(Model $actor, string $role = 'member'): ConversationParticipant
    {
        return $this->participants()->firstOrCreate(
            [
                'participantable_type' => $actor::class,
                'participantable_id'   => $actor->getKey(),
            ],
            ['role' => $role]
        );
    }

    public static function findByExternalId(ChannelType $channelType, string $externalId): ?self
    {
        return self::where('channel_type', $channelType)
            ->where('external_id', $externalId)
            ->first();
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('participants', function (Builder $q) use ($user) {
            $q->where('participantable_type', User::class)
              ->where('participantable_id', $user->getKey());
        })->where('channel_type', ChannelType::Web);
    }
}
