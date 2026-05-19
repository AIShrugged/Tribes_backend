<?php

namespace App\Services\Channel\Delivery;

use App\Enums\ConversationChannelType;
use App\Models\ChannelMessage;
use App\Services\Channel\ChannelBus;
use Telegram\Bot\Api;

class TelegramDelivery implements ChannelDeliveryInterface
{
    public function __construct(
        private readonly ChannelBus $channelBus,
    ) {}

    public function channelType(): ConversationChannelType
    {
        return ConversationChannelType::TELEGRAM;
    }

    public function deliver(ChannelDeliveryRequest $request): ?ChannelMessage
    {
        $telegram = new Api(config('telegram.bot_token'));
        $params = [
            'chat_id' => $request->conversation->telegram_chat_id,
            'text' => $request->content,
            'parse_mode' => 'Markdown',
        ];

        if ($request->conversation->message_thread_id) {
            $params['message_thread_id'] = $request->conversation->message_thread_id;
        }

        try {
            $sent = $telegram->sendMessage($params);
        } catch (\Throwable $exception) {
            if (! $this->shouldRetryWithoutFormatting($exception)) {
                throw $exception;
            }

            unset($params['parse_mode']);
            $sent = $telegram->sendMessage($params);
        }

        $attributes = $request->attributes;
        $telegramMessageId = $sent?->getMessageId();
        if ($telegramMessageId !== null) {
            $metadata = (array) ($attributes['metadata'] ?? []);
            $metadata['telegram_message_id'] = (int) $telegramMessageId;
            $attributes['metadata'] = $metadata;
        }

        return $this->channelBus->appendTelegramMessage(
            (int) $request->conversation->telegram_chat_id,
            null,
            $request->conversation->message_thread_id,
            'assistant',
            $request->content,
            $attributes,
        );
    }

    private function shouldRetryWithoutFormatting(\Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, "can't parse entities")
            || str_contains($message, 'cant parse entities');
    }
}
