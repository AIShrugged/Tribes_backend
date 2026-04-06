<?php

namespace App\Services\Channel;

use App\Enums\ChatRunStatus;
use App\Enums\ConversationChannelType;
use App\Models\ChannelConversation;
use App\Models\ChannelIdentity;
use App\Models\ChannelMessage;
use App\Models\Chat;
use App\Models\TelegramUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChannelBus
{
    public function forChat(Chat $chat): ChannelConversation
    {
        $conversation = ChannelConversation::firstOrCreate(
            ['conversation_key' => ChannelConversation::keyForChat($chat->id)],
            [
                'channel_type' => ConversationChannelType::WEB_CHAT->value,
                'user_id' => $chat->user_id,
                'organization_id' => $chat->organization_id,
                'team_id' => $chat->team_id,
                'chat_id' => $chat->id,
                'title' => $chat->title,
            ]
        );

        $conversation->forceFill([
            'user_id' => $chat->user_id,
            'organization_id' => $chat->organization_id,
            'team_id' => $chat->team_id,
            'chat_id' => $chat->id,
            'title' => $chat->title,
        ])->save();

        return $conversation;
    }

    public function forTelegram(int $chatId, ?int $messageThreadId = null): ChannelConversation
    {
        return ChannelConversation::firstOrCreate(
            ['conversation_key' => ChannelConversation::keyForTelegram($chatId, $messageThreadId)],
            [
                'channel_type' => ConversationChannelType::TELEGRAM->value,
                'telegram_chat_id' => $chatId,
                'message_thread_id' => $messageThreadId,
            ]
        );
    }

    public function getChatMessages(Chat $chat, int $offset = 0, int $limit = 50): Collection
    {
        return $this->forChat($chat)
            ->messages()
            ->orderBy('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    public function countChatMessages(Chat $chat): int
    {
        return $this->forChat($chat)->messages()->count();
    }

    public function getRecentChatHistory(Chat $chat, int $limit = 20): Collection
    {
        return $this->forChat($chat)
            ->messages()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    public function findChatAssistantRun(Chat $chat, string $runUuid): ?ChannelMessage
    {
        if (! Str::isUuid($runUuid)) {
            return null;
        }

        return $this->forChat($chat)
            ->messages()
            ->where('role', 'assistant')
            ->where('agent_run_uuid', $runUuid)
            ->first();
    }

    public function createChatUserMessage(Chat $chat, string $content, array $attributes = []): ChannelMessage
    {
        return $this->createMessage(
            $this->forChat($chat),
            'user',
            $content,
            $attributes,
        );
    }

    public function createChatAssistantMessage(Chat $chat, string $content, ?array $followupData = null): ChannelMessage
    {
        return $this->createMessage(
            $this->forChat($chat),
            'assistant',
            $content,
            ['followup_data' => $followupData]
        );
    }

    public function createQueuedChatAssistantMessage(Chat $chat, string $content = 'Processing...'): ChannelMessage
    {
        return $this->createMessage(
            $this->forChat($chat),
            'assistant',
            $content,
            [
                'status' => ChatRunStatus::QUEUED->value,
                'agent_run_uuid' => (string) Str::uuid(),
                'current_attempt' => 0,
                'max_attempts' => (int) config('agent.chat.max_attempts', 3),
            ]
        );
    }

    public function appendTelegramMessage(
        int $chatId,
        ?TelegramUser $telegramUser,
        ?int $messageThreadId,
        string $role,
        string $content,
        array $attributes = []
    ): ChannelMessage {
        return $this->createMessage(
            $this->forTelegram($chatId, $messageThreadId),
            $role,
            $content,
            [
                'author_identity_id' => $telegramUser ? $this->resolveTelegramIdentity($telegramUser)->id : null,
                ...$attributes,
            ]
        );
    }

    public function updateMessageContent(ChannelMessage $message, string $content): void
    {
        $message->update(['content' => $content]);
    }

    public function appendAssistantMessage(
        ChannelConversation $conversation,
        string $content,
        array $attributes = []
    ): ChannelMessage {
        return $this->createMessage(
            $conversation,
            'assistant',
            $content,
            $attributes,
        );
    }

    public function claimTelegramPendingBatch(int $chatId, ?int $messageThreadId = null): ?array
    {
        $conversation = $this->forTelegram($chatId, $messageThreadId);

        return DB::transaction(function () use ($conversation, $chatId, $messageThreadId) {
            $messages = ChannelMessage::query()
                ->with('authorIdentity')
                ->where('conversation_id', $conversation->id)
                ->where('role', 'user')
                ->whereNull('coalesced_at')
                ->whereNull('responded_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($messages->isEmpty()) {
                return null;
            }

            $batchUuid = (string) Str::uuid();
            $now = now();

            ChannelMessage::query()
                ->whereIn('id', $messages->pluck('id'))
                ->update([
                    'agent_batch_uuid' => $batchUuid,
                    'coalesced_at' => $now,
                ]);

            $messages->each(function (ChannelMessage $message) use ($batchUuid, $now) {
                $message->agent_batch_uuid = $batchUuid;
                $message->coalesced_at = $now;
            });

            $participants = $messages
                ->pluck('authorIdentity')
                ->filter()
                ->unique('id')
                ->values();

            $content = $messages
                ->map(function (ChannelMessage $message, int $index) {
                    $author = $message->authorIdentity?->display_name
                        ?: $message->authorIdentity?->username
                        ?: $message->authorIdentity?->external_id
                        ?: 'unknown';

                    return sprintf('%d. [%s] %s', $index + 1, $author, trim($message->content));
                })
                ->implode("\n");

            return [
                'conversation' => $conversation,
                'messages' => $messages,
                'batch_uuid' => $batchUuid,
                'content' => $content,
                'chat_id' => $chatId,
                'message_thread_id' => $messageThreadId,
                'participants' => $participants,
                'initiator_identity' => $messages->last()->authorIdentity,
                'author_identity' => $messages->last()->authorIdentity,
            ];
        });
    }

    public function markTelegramBatchResponded(string $batchUuid): void
    {
        ChannelMessage::query()
            ->where('agent_batch_uuid', $batchUuid)
            ->update(['responded_at' => now()]);
    }

    public function releaseTelegramBatch(string $batchUuid): void
    {
        ChannelMessage::query()
            ->where('agent_batch_uuid', $batchUuid)
            ->update([
                'agent_batch_uuid' => null,
                'coalesced_at' => null,
            ]);
    }

    private function createMessage(
        ChannelConversation $conversation,
        string $role,
        string $content,
        array $attributes = []
    ): ChannelMessage {
        $message = ChannelMessage::create([
            'conversation_id' => $conversation->id,
            'role' => $role,
            'status' => ChatRunStatus::COMPLETED->value,
            'content' => $content,
            ...$attributes,
        ]);

        $conversation->forceFill(['latest_message_at' => $message->created_at])->save();

        if ($message->author_identity_id) {
            $this->touchParticipant(
                $conversation->id,
                (int) $message->author_identity_id,
                $message->created_at,
            );
        }

        if ($conversation->chat) {
            $conversation->chat->touch();
        }

        return $message;
    }

    private function resolveTelegramIdentity(TelegramUser $telegramUser): ChannelIdentity
    {
        return ChannelIdentity::updateOrCreate(
            [
                'channel_type' => ConversationChannelType::TELEGRAM->value,
                'external_id' => (string) $telegramUser->telegram_user_id,
            ],
            [
                'user_id' => $telegramUser->user_id,
                'display_name' => $telegramUser->telegram_username,
                'username' => $telegramUser->telegram_username,
                'metadata' => [
                    'legacy_telegram_user_id' => $telegramUser->telegram_user_id,
                ],
            ]
        );
    }

    private function touchParticipant(int $conversationId, int $identityId, $messageAt): void
    {
        \App\Models\ChannelConversationParticipant::query()->updateOrCreate(
            [
                'conversation_id' => $conversationId,
                'channel_identity_id' => $identityId,
            ],
            [
                'joined_at' => $messageAt,
                'last_message_at' => $messageAt,
            ]
        );
    }
}
