<?php

namespace App\Services\Channel;

use App\Jobs\ProcessChatBranchJob;
use App\Jobs\ProcessChatWorkerJob;
use App\Jobs\ProcessTelegramBranchJob;
use App\Jobs\ProcessTelegramWorkerJob;
use App\Models\ChannelConversation;
use App\Models\ChannelIdentity;
use App\Models\ChannelMessage;
use App\Models\Chat;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Agent\TelegramMessageCoalescer;
use App\Services\Channel\Delivery\ChannelDeliveryRegistry;
use App\Services\Channel\Delivery\ChannelDeliveryRequest;

class ChannelRuntimeService
{
    public function __construct(
        private readonly ChannelBus $channelBus,
        private readonly TelegramMessageCoalescer $telegramCoalescer,
        private readonly ChannelDeliveryRegistry $deliveryRegistry,
    ) {}

    public function queueWebChatRun(User $user, Chat $chat, string $content): ChannelMessage
    {
        $userMessage = $this->channelBus->createChatUserMessage($chat, $content);
        $assistantMessage = $this->channelBus->createQueuedChatAssistantMessage($chat);

        $this->dispatchChatBranch($chat, $user, $userMessage, $assistantMessage);

        return $assistantMessage;
    }

    public function dispatchChatBranch(Chat $chat, User $user, ChannelMessage $userMessage, ChannelMessage $assistantMessage): void
    {
        ProcessChatBranchJob::dispatch(
            $chat->id,
            $user->id,
            $userMessage->id,
            $assistantMessage->id,
        );
    }

    public function handleChatBranch(int $chatId, int $userId, int $userMessageId, int $assistantMessageId): void
    {
        ProcessChatWorkerJob::dispatch(
            $chatId,
            $userId,
            $userMessageId,
            $assistantMessageId,
        );
    }

    public function recordTelegramInbound(TelegramUser $telegramUser, int $chatId, ?int $messageThreadId, string $content): ChannelMessage
    {
        return $this->channelBus->appendTelegramMessage(
            $chatId,
            $telegramUser,
            $messageThreadId,
            'user',
            $content,
        );
    }

    public function scheduleTelegramBranch(int $chatId, ?int $messageThreadId = null): void
    {
        ProcessTelegramBranchJob::dispatch($chatId, $messageThreadId)
            ->delay(now()->addSeconds((int) config('agent.telegram.coalesce_window_seconds', 4)));
    }

    public function handleTelegramBranch(int $chatId, ?int $messageThreadId = null): void
    {
        $batch = $this->telegramCoalescer->claimPendingBatch($chatId, $messageThreadId);

        if (! $batch || ! $batch->authorIdentity->user) {
            return;
        }

        ProcessTelegramWorkerJob::dispatch(
            $batch->chatId,
            $batch->authorIdentity->id,
            $batch->authorIdentity->user->id,
            $batch->batchUuid,
            $batch->content,
            $batch->messageThreadId,
        );
    }

    public function deliverToWebChat(ChannelMessage $assistantMessage, string $content, array $attributes = []): ChannelMessage
    {
        $conversation = $assistantMessage->conversation()->firstOrFail();

        return $this->deliveryRegistry
            ->for($conversation->channel_type)
            ->deliver(new ChannelDeliveryRequest(
                channelType: $conversation->channel_type,
                conversation: $conversation,
                content: $content,
                targetMessage: $assistantMessage,
                attributes: $attributes,
            )) ?? $assistantMessage;
    }

    public function deliverToConversation(
        ChannelConversation $conversation,
        string $content,
        ?ChannelIdentity $authorIdentity = null,
        array $attributes = []
    ): ?ChannelMessage {
        return $this->deliveryRegistry
            ->for($conversation->channel_type)
            ->deliver(new ChannelDeliveryRequest(
                channelType: $conversation->channel_type,
                conversation: $conversation,
                content: $content,
                authorIdentity: $authorIdentity,
                attributes: $attributes,
            ));
    }
}
