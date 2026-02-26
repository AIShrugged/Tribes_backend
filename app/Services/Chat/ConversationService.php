<?php

namespace App\Services\Chat;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ConversationService
{
    public function getConversationsForUser(User $user, int $offset = 0, int $limit = 10): Collection
    {
        return Conversation::forUser($user)
            ->orderByDesc('updated_at')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    public function countConversationsForUser(User $user): int
    {
        return Conversation::forUser($user)->count();
    }

    public function createWebConversation(User $user, ?string $title = null): Conversation
    {
        $conversation = Conversation::create([
            'channel_type' => ChannelType::Web,
            'title'        => $title,
        ]);

        $conversation->addParticipant($user, 'owner');

        return $conversation;
    }

    public function findOrCreateTelegramConversation(
        TelegramUser $telegramUser,
        int $externalChatId,
        ChannelType $channelType,
    ): Conversation {
        $externalId = (string) $externalChatId;

        $conversation = Conversation::findByExternalId($channelType, $externalId);

        if (!$conversation) {
            $conversation = Conversation::create([
                'channel_type' => $channelType,
                'external_id'  => $externalId,
            ]);
        }

        if (!$conversation->hasParticipant($telegramUser)) {
            $conversation->addParticipant($telegramUser, 'member');
        }

        return $conversation;
    }

    public function update(Conversation $conversation, ?string $title): Conversation
    {
        $conversation->update(['title' => $title]);

        return $conversation;
    }

    public function delete(Conversation $conversation): void
    {
        $conversation->delete();
    }

    public function findOrFail(int $id): Conversation
    {
        return Conversation::findOrFail($id);
    }
}
