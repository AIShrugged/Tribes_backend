<?php

namespace App\Services\Channel\Delivery;

use App\Enums\ConversationChannelType;
use App\Models\ChannelMessage;
use App\Services\Channel\ChannelBus;

class WebChatDelivery implements ChannelDeliveryInterface
{
    public function __construct(
        private readonly ChannelBus $channelBus,
    ) {}

    public function channelType(): ConversationChannelType
    {
        return ConversationChannelType::WEB_CHAT;
    }

    public function deliver(ChannelDeliveryRequest $request): ?ChannelMessage
    {
        if (! $request->targetMessage) {
            return $this->channelBus->appendAssistantMessage(
                $request->conversation,
                $request->content,
                $request->attributes,
            );
        }

        $request->targetMessage->markCompleted([
            'content' => $request->content,
            'completed_at' => now(),
            'error_message' => null,
            'failure_code' => null,
            'next_retry_at' => null,
            ...$request->attributes,
        ]);

        return $request->targetMessage->fresh();
    }
}
