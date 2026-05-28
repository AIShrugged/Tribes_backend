<?php

namespace App\Services;

use App\Models\ChannelConversation;
use App\Models\TelegramChatRegistration;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class TelegramChatRegistrationService
{
    public function bindPrivateConversation(ChannelConversation $conversation, User $user): TelegramChatRegistration
    {
        $conversation->forceFill([
            'user_id' => $user->id,
            'organization_id' => null,
            'team_id' => null,
        ])->save();

        $registration = $conversation->telegramRegistration()->firstOrCreate(
            ['channel_conversation_id' => $conversation->id],
            [
                'telegram_chat_id' => $conversation->telegram_chat_id,
                'message_thread_id' => $conversation->message_thread_id,
                'chat_type' => 'private',
                'chat_title' => $conversation->title,
            ],
        );

        $registration->forceFill([
            'organization_id' => null,
            'team_id' => null,
            'attach_code' => null,
            'attach_code_issued_at' => null,
            'attach_code_expires_at' => null,
            'attach_code_used_at' => now(),
            'attach_requested_by_user_id' => $user->id,
            'bound_at' => now(),
            'bound_by_user_id' => $user->id,
        ])->save();

        return $registration->refresh();
    }

    public function createWorkspaceChat(
        ?string $name,
        int $telegramChatId,
        ?int $messageThreadId,
        int $organizationId,
        ?int $teamId,
        User $createdBy,
    ): TelegramChatRegistration {
        $existing = TelegramChatRegistration::query()
            ->where('telegram_chat_id', $telegramChatId)
            ->when(
                $messageThreadId === null,
                fn ($query) => $query->whereNull('message_thread_id'),
                fn ($query) => $query->where('message_thread_id', $messageThreadId),
            )
            ->first();

        if ($existing?->organization_id !== null) {
            throw ValidationException::withMessages([
                'telegram_chat_id' => ['A workspace chat with this Telegram chat ID and topic is already registered.'],
            ]);
        }

        if ($existing !== null) {
            $existing->forceFill([
                'organization_id' => $organizationId,
                'team_id' => $teamId,
                'chat_title' => $name ?? $existing->chat_title,
                'attach_requested_by_user_id' => $createdBy->id,
                'bound_at' => now(),
                'bound_by_user_id' => $createdBy->id,
            ])->save();

            return $existing->refresh();
        }

        try {
            return TelegramChatRegistration::query()->create([
                'channel_conversation_id' => null,
                'telegram_chat_id' => $telegramChatId,
                'message_thread_id' => $messageThreadId,
                'chat_title' => $name,
                'chat_type' => 'group',
                'organization_id' => $organizationId,
                'team_id' => $teamId,
                'attach_requested_by_user_id' => $createdBy->id,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw ValidationException::withMessages([
                    'telegram_chat_id' => ['A workspace chat with this Telegram chat ID and topic is already registered.'],
                ]);
            }

            throw $exception;
        }
    }

    public function discoverGroupConversation(
        ChannelConversation $conversation,
        string $chatType,
        ?string $chatTitle,
        ?string $topicTitle = null,
    ): TelegramChatRegistration {
        if ($conversation->message_thread_id !== null && $topicTitle !== null && trim($topicTitle) !== '') {
            $conversation->forceFill(['title' => trim($topicTitle)])->save();
        }

        $registration = TelegramChatRegistration::query()->updateOrCreate(
            [
                'telegram_chat_id' => $conversation->telegram_chat_id,
                'message_thread_id' => $conversation->message_thread_id,
            ],
            [
                'channel_conversation_id' => $conversation->id,
                'chat_type' => $chatType,
            ],
        );

        // Update title only if manager hasn't set one yet
        if (empty($registration->chat_title) && $chatTitle) {
            $registration->forceFill(['chat_title' => $chatTitle])->save();
        }

        // Both conditions met — bind the chat
        if ($registration->organization_id !== null && $registration->bound_at === null) {
            $registration->forceFill(['bound_at' => now()])->save();
        }

        return $registration->refresh();
    }

    public function unbindGroupConversation(int $telegramChatId): void
    {
        TelegramChatRegistration::query()
            ->where('telegram_chat_id', $telegramChatId)
            ->whereNull('message_thread_id')
            ->whereNotNull('bound_at')
            ->update(['bound_at' => null]);
    }

    public function destroy(TelegramChatRegistration $registration): void
    {
        if ($registration->channel_conversation_id !== null) {
            $registration->conversation->delete();
        } else {
            $registration->delete();
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}
