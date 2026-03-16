<?php

namespace App\Services\Channel\Delivery;

use App\Enums\ConversationChannelType;
use LogicException;

class ChannelDeliveryRegistry
{
    /** @var array<string, ChannelDeliveryInterface> */
    private array $deliveries;

    public function __construct(
        WebChatDelivery $webChatDelivery,
        TelegramDelivery $telegramDelivery,
    ) {
        $this->deliveries = [
            $webChatDelivery->channelType()->value => $webChatDelivery,
            $telegramDelivery->channelType()->value => $telegramDelivery,
        ];
    }

    public function for(ConversationChannelType $channelType): ChannelDeliveryInterface
    {
        $delivery = $this->deliveries[$channelType->value] ?? null;

        if (! $delivery) {
            throw new LogicException(sprintf('No delivery registered for channel type [%s]', $channelType->value));
        }

        return $delivery;
    }
}
