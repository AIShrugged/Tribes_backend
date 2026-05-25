<?php

namespace Tests\Feature;

use App\Enums\ConversationChannelType;
use App\Services\Channel\ChannelBus;
use App\Services\Channel\Delivery\ChannelDeliveryRequest;
use App\Services\Channel\Delivery\TelegramDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Objects\Message as TelegramMessage;
use Tests\TestCase;

class TelegramDeliveryMessageIdTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deliver_captures_telegram_message_id_into_metadata(): void
    {
        $channelBus = $this->app->make(ChannelBus::class);
        $conversation = $channelBus->forTelegram(4242);

        $api = Mockery::mock('overload:Telegram\Bot\Api');
        $api->shouldReceive('sendMessage')
            ->once()
            ->andReturn(new TelegramMessage(['message_id' => 7777]));

        $delivery = new TelegramDelivery($channelBus);

        $message = $delivery->deliver(new ChannelDeliveryRequest(
            channelType: ConversationChannelType::TELEGRAM,
            conversation: $conversation,
            content: 'Test message',
        ));

        $this->assertNotNull($message);
        $this->assertSame(7777, $message->metadata['telegram_message_id'] ?? null);
    }

    #[Test]
    public function deliver_splits_long_messages_before_sending_to_telegram(): void
    {
        $channelBus = $this->app->make(ChannelBus::class);
        $conversation = $channelBus->forTelegram(4243);
        $content = str_repeat('Long agent response line. ', 220);
        $sentPayloads = [];
        $messageId = 9000;

        $api = Mockery::mock('overload:Telegram\Bot\Api');
        $api->shouldReceive('sendMessage')
            ->twice()
            ->andReturnUsing(function (array $payload) use (&$sentPayloads, &$messageId) {
                $sentPayloads[] = $payload;

                return new TelegramMessage(['message_id' => ++$messageId]);
            });

        $delivery = new TelegramDelivery($channelBus);

        $message = $delivery->deliver(new ChannelDeliveryRequest(
            channelType: ConversationChannelType::TELEGRAM,
            conversation: $conversation,
            content: $content,
        ));

        $this->assertCount(2, $sentPayloads);
        $this->assertStringStartsWith('(part 1/2)', $sentPayloads[0]['text']);
        $this->assertStringStartsWith('(part 2/2)', $sentPayloads[1]['text']);
        $this->assertSame($content, $message?->content);
        $this->assertSame(9001, $message?->metadata['telegram_message_id'] ?? null);
        $this->assertSame([9001, 9002], $message?->metadata['telegram_message_ids'] ?? null);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
