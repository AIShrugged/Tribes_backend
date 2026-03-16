<?php

namespace App\Services\Channel\Delivery;

use App\Enums\ConversationChannelType;
use App\Models\ChannelMessage;

interface ChannelDeliveryInterface
{
    public function channelType(): ConversationChannelType;

    public function deliver(ChannelDeliveryRequest $request): ?ChannelMessage;
}
