<?php

namespace Tests\Unit;

use App\Services\Channel\TelegramTypingIndicator;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Tests\TestCase;

class TelegramTypingIndicatorTest extends TestCase
{
    #[Test]
    public function start_sends_typing_and_touch_is_throttled_within_interval(): void
    {
        config(['agent.telegram.typing_interval_seconds' => 30]);
        Cache::flush();

        $apiMock = Mockery::mock('overload:'.Api::class);
        $apiMock->shouldReceive('__construct')->andReturnNull();
        $apiMock->shouldReceive('sendChatAction')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool => (int) $payload['chat_id'] === 1001 && $payload['action'] === 'typing'))
            ->andReturnTrue();

        $indicator = app(TelegramTypingIndicator::class);
        $sessionId = $indicator->sessionId(1001);

        $indicator->start($sessionId, 1001);
        $indicator->touch($sessionId);
    }

    #[Test]
    public function stop_clears_the_typing_session(): void
    {
        config(['agent.telegram.typing_interval_seconds' => 30]);
        Cache::flush();

        $apiMock = Mockery::mock('overload:'.Api::class);
        $apiMock->shouldReceive('__construct')->andReturnNull();
        $apiMock->shouldReceive('sendChatAction')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool => (int) $payload['chat_id'] === 1002 && $payload['action'] === 'typing'))
            ->andReturnTrue();

        $indicator = app(TelegramTypingIndicator::class);
        $sessionId = $indicator->sessionId(1002);

        $indicator->start($sessionId, 1002);
        $indicator->stop($sessionId);

        $this->assertNull($indicator->get($sessionId));
        $this->assertNull(Cache::get('telegram_typing_last_sent:'.$sessionId));
    }
}
