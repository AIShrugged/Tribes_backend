<?php

namespace App\Services\Channel;

use App\Enums\ConversationChannelType;
use App\Models\ChannelConversation;
use App\Models\ChannelIdentity;
use App\Models\Chat;
use App\Models\TelegramUser;
use App\Models\User;

class UserChannelTargetResolver
{
    public function __construct(
        private readonly ChannelBus $channelBus,
    ) {}

    public function resolve(
        User $user,
        ConversationChannelType $channelType,
        ?int $chatId = null,
        ?int $telegramChatId = null,
        ?int $messageThreadId = null,
    ): ?ChannelConversation {
        return match ($channelType) {
            ConversationChannelType::WEB_CHAT => $this->resolveWebChatConversation($user, $chatId),
            ConversationChannelType::TELEGRAM => $this->resolveTelegramConversation($user, $telegramChatId, $messageThreadId),
        };
    }

    private function resolveWebChatConversation(User $user, ?int $chatId = null): ?ChannelConversation
    {
        $chatQuery = Chat::query()->ownedBy($user->id);

        if ($chatId !== null) {
            $chat = $chatQuery->where('id', $chatId)->first();

            return $chat ? $this->channelBus->forChat($chat) : null;
        }

        $chat = $chatQuery
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $chat ? $this->channelBus->forChat($chat) : null;
    }

    private function resolveTelegramConversation(User $user, ?int $telegramChatId = null, ?int $messageThreadId = null): ?ChannelConversation
    {
        $query = ChannelConversation::query()
            ->where('channel_type', ConversationChannelType::TELEGRAM->value)
            ->whereHas('identities', fn ($builder) => $builder->where('channel_identities.user_id', $user->id));

        if ($telegramChatId !== null) {
            $query->where('telegram_chat_id', $telegramChatId);
        }

        if ($messageThreadId !== null) {
            $query->where('message_thread_id', $messageThreadId);
        }

        $conversation = $query
            ->orderByDesc('latest_message_at')
            ->orderByDesc('id')
            ->first();

        if ($conversation !== null || $telegramChatId === null) {
            return $conversation;
        }

        $telegramUser = $user->telegramUser;
        if (! $telegramUser) {
            return null;
        }

        return $this->createTelegramConversationForUser($user, $telegramUser, $telegramChatId, $messageThreadId);
    }

    private function createTelegramConversationForUser(
        User $user,
        TelegramUser $telegramUser,
        int $telegramChatId,
        ?int $messageThreadId = null,
    ): ChannelConversation {
        $conversation = $this->channelBus->forTelegram($telegramChatId, $messageThreadId);

        $identity = ChannelIdentity::query()->firstOrCreate(
            [
                'channel_type' => ConversationChannelType::TELEGRAM->value,
                'external_id' => (string) $telegramUser->telegram_user_id,
            ],
            [
                'user_id' => $user->id,
                'display_name' => $telegramUser->telegram_username,
                'username' => $telegramUser->telegram_username,
                'metadata' => [
                    'legacy_telegram_user_id' => $telegramUser->telegram_user_id,
                ],
            ]
        );

        if ($identity->user_id !== $user->id) {
            $identity->forceFill([
                'user_id' => $user->id,
                'display_name' => $identity->display_name ?: $telegramUser->telegram_username,
                'username' => $identity->username ?: $telegramUser->telegram_username,
            ])->save();
        }

        $conversation->participants()->updateOrCreate(
            ['channel_identity_id' => $identity->id],
            [
                'joined_at' => now(),
                'last_message_at' => now(),
            ]
        );

        $conversation->forceFill(['latest_message_at' => now()])->save();

        return $conversation;
    }
}
