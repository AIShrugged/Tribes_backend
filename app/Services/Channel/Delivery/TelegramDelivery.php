<?php

namespace App\Services\Channel\Delivery;

use App\Enums\ConversationChannelType;
use App\Models\ChannelMessage;
use App\Services\Channel\ChannelBus;
use App\Services\Telegram\TelegramHtmlFormatter;
use App\Services\Telegram\TelegramMessageSplitter;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message as TelegramMessage;

class TelegramDelivery implements ChannelDeliveryInterface
{
    public function __construct(
        private readonly ChannelBus $channelBus,
        private readonly ?TelegramMessageSplitter $splitter = null,
        private readonly ?TelegramHtmlFormatter $formatter = null,
    ) {}

    public function channelType(): ConversationChannelType
    {
        return ConversationChannelType::TELEGRAM;
    }

    public function deliver(ChannelDeliveryRequest $request): ?ChannelMessage
    {
        $telegram = new Api(config('telegram.bot_token'));
        $baseParams = [
            'chat_id' => $request->conversation->telegram_chat_id,
            'parse_mode' => 'HTML',
        ];

        if ($request->conversation->message_thread_id) {
            $baseParams['message_thread_id'] = $request->conversation->message_thread_id;
        }

        $formatter = $this->formatter ?? app(TelegramHtmlFormatter::class);

        // Split the raw Markdown first, then format each chunk independently, so
        // an HTML tag can never be torn across a chunk boundary.
        $chunks = ($this->splitter ?? app(TelegramMessageSplitter::class))->split($request->content);
        $total = count($chunks);
        $sentMessages = [];

        foreach ($chunks as $index => $chunk) {
            $header = $total > 1 ? '(part '.($index + 1).'/'.$total.')'."\n\n" : '';
            $text = $header.$formatter->toHtml($chunk);
            $plainFallback = $header.$formatter->toPlainText($chunk);

            $sentMessages[] = $this->sendChunk($telegram, $baseParams, $text, $plainFallback);
        }

        $attributes = $request->attributes;
        $telegramMessageIds = array_values(array_filter(
            array_map(
                static fn (?TelegramMessage $message): ?int => $message?->getMessageId(),
                $sentMessages,
            ),
            static fn (?int $messageId): bool => $messageId !== null,
        ));

        if ($telegramMessageIds !== []) {
            $metadata = (array) ($attributes['metadata'] ?? []);
            $metadata['telegram_message_id'] = $telegramMessageIds[0];
            $metadata['telegram_message_ids'] = $telegramMessageIds;
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

    /**
     * @param  array<string, mixed>  $baseParams
     */
    private function sendChunk(Api $telegram, array $baseParams, string $text, ?string $plainFallback = null): ?TelegramMessage
    {
        $params = $baseParams + ['text' => $text];

        try {
            return $telegram->sendMessage($params);
        } catch (\Throwable $exception) {
            if (! $this->shouldRetryWithoutFormatting($exception)) {
                throw $exception;
            }

            unset($params['parse_mode']);
            $params['text'] = $plainFallback ?? $text;

            return $telegram->sendMessage($params);
        }
    }

    private function shouldRetryWithoutFormatting(\Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, "can't parse entities")
            || str_contains($message, 'cant parse entities');
    }
}
