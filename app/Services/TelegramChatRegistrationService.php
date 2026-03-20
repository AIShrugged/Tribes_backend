<?php

namespace App\Services;

use App\Models\ChannelConversation;
use App\Models\TelegramChatRegistration;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TelegramChatRegistrationService
{
    public function registerConversation(
        ChannelConversation $conversation,
        ?string $chatType = null,
        ?string $chatTitle = null,
    ): TelegramChatRegistration {
        return TelegramChatRegistration::query()->updateOrCreate(
            ['channel_conversation_id' => $conversation->id],
            [
                'telegram_chat_id' => $conversation->telegram_chat_id,
                'message_thread_id' => $conversation->message_thread_id,
                'chat_type' => $chatType,
                'chat_title' => $chatTitle,
            ],
        );
    }

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

    public function issueAttachCode(
        TelegramChatRegistration $registration,
        User $requestedBy,
        int $organizationId,
        ?int $teamId,
    ): TelegramChatRegistration {
        if ($registration->chat_type === 'private') {
            throw ValidationException::withMessages([
                'telegram_chat' => ['Private chats are connected to a user and cannot be attached to an organization or team.'],
            ]);
        }

        $registration->forceFill([
            'attach_code' => $this->generateAttachCode(),
            'organization_id' => $organizationId,
            'team_id' => $teamId,
            'attach_requested_by_user_id' => $requestedBy->id,
            'attach_code_issued_at' => now(),
            'attach_code_expires_at' => now()->addMinutes(30),
            'attach_code_used_at' => null,
            'bound_at' => null,
            'bound_by_user_id' => null,
        ])->save();

        return $registration->refresh();
    }

    public function attachConversationByCode(
        ChannelConversation $conversation,
        string $code,
        User $user,
    ): TelegramChatRegistration {
        $registration = TelegramChatRegistration::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('attach_code', Str::upper($code))
            ->first();

        if (! $registration) {
            throw ValidationException::withMessages([
                'attach_code' => ['Attach code is invalid for this chat.'],
            ]);
        }

        if ($registration->chat_type === 'private') {
            throw ValidationException::withMessages([
                'attach_code' => ['Private chats are connected automatically and do not support attach codes.'],
            ]);
        }

        if ($registration->attach_code_used_at !== null) {
            throw ValidationException::withMessages([
                'attach_code' => ['Attach code has already been used.'],
            ]);
        }

        if ($registration->attach_code_expires_at === null || $registration->attach_code_expires_at->isPast()) {
            throw ValidationException::withMessages([
                'attach_code' => ['Attach code has expired.'],
            ]);
        }

        if (! $user->isOrganizationManager((int) $registration->organization_id)) {
            throw ValidationException::withMessages([
                'attach_code' => ['Only an organization manager can attach this chat.'],
            ]);
        }

        $conversation->forceFill([
            'user_id' => $user->id,
            'organization_id' => $registration->organization_id,
            'team_id' => $registration->team_id,
        ])->save();

        $registration->forceFill([
            'attach_code_used_at' => now(),
            'bound_at' => now(),
            'bound_by_user_id' => $user->id,
        ])->save();

        return $registration->refresh();
    }

    private function generateAttachCode(): string
    {
        do {
            $candidate = $this->randomChunk(3).'-'.$this->randomChunk(3);
        } while (TelegramChatRegistration::query()->where('attach_code', $candidate)->exists());

        return $candidate;
    }

    private function randomChunk(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $chunk = '';

        for ($i = 0; $i < $length; $i++) {
            $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $chunk;
    }
}
