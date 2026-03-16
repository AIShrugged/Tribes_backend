<?php

namespace App\Services\Channel\Delivery;

use App\Enums\ConversationChannelType;
use App\Models\ChannelConversation;
use App\Models\ChannelIdentity;
use App\Models\ChannelMessage;

class ChannelDeliveryRequest
{
    public function __construct(
        public readonly ConversationChannelType $channelType,
        public readonly ChannelConversation $conversation,
        public readonly string $content,
        public readonly ?ChannelMessage $targetMessage = null,
        public readonly ?ChannelIdentity $authorIdentity = null,
        public readonly array $attributes = [],
    ) {}
}
