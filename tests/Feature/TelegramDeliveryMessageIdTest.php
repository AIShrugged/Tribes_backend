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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
