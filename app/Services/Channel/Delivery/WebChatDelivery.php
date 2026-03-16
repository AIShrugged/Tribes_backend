<?php

namespace App\Services\Channel\Delivery;

use App\Enums\ConversationChannelType;
use App\Models\ChannelMessage;

class WebChatDelivery implements ChannelDeliveryInterface
{
    public function channelType(): ConversationChannelType
    {
        return ConversationChannelType::WEB_CHAT;
    }

    public function deliver(ChannelDeliveryRequest $request): ?ChannelMessage
    {
        if (! $request->targetMessage) {
            return null;
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
