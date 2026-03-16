<?php

namespace Tests\Feature;

use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Agent\TelegramMessageCoalescer;
use App\Services\Channel\ChannelBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelegramMessageCoalescerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_batches_pending_messages_for_a_chat(): void
    {
        $user = User::factory()->create();
        $telegramUser = TelegramUser::create([
            'telegram_user_id' => 12345,
            'telegram_username' => 'tester',
            'user_id' => $user->id,
        ]);

        $channelBus = $this->app->make(ChannelBus::class);
        $conversationId = $channelBus->forTelegram(77)->id;

        $channelBus->appendTelegramMessage(77, $telegramUser, null, 'user', 'First message');
        $channelBus->appendTelegramMessage(77, $telegramUser, null, 'user', 'Second message');

        $batch = app(TelegramMessageCoalescer::class)->claimPendingBatch(77);

        $this->assertNotNull($batch);
        $this->assertCount(2, $batch->messages);
        $this->assertCount(1, $batch->participants);
        $this->assertStringContainsString('1. [tester] First message', $batch->content);
        $this->assertStringContainsString('2. [tester] Second message', $batch->content);

        $this->assertDatabaseCount('channel_messages', 2);
        $this->assertDatabaseHas('channel_messages', [
            'conversation_id' => $conversationId,
            'content' => 'First message',
            'agent_batch_uuid' => $batch->batchUuid,
        ]);
    }

    #[Test]
    public function it_tracks_multiple_participants_in_a_group_batch(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $telegramUserA = TelegramUser::create([
            'telegram_user_id' => 12345,
            'telegram_username' => 'alice',
            'user_id' => $userA->id,
        ]);

        $telegramUserB = TelegramUser::create([
            'telegram_user_id' => 54321,
            'telegram_username' => 'bob',
            'user_id' => $userB->id,
        ]);

        $channelBus = $this->app->make(ChannelBus::class);
        $conversationId = $channelBus->forTelegram(91)->id;

        $channelBus->appendTelegramMessage(91, $telegramUserA, null, 'user', 'First from Alice');
        $channelBus->appendTelegramMessage(91, $telegramUserB, null, 'user', 'Then from Bob');

        $batch = app(TelegramMessageCoalescer::class)->claimPendingBatch(91);

        $this->assertNotNull($batch);
        $this->assertSame('54321', $batch->authorIdentity->external_id);
        $this->assertCount(2, $batch->participants);
        $this->assertStringContainsString('[alice] First from Alice', $batch->content);
        $this->assertStringContainsString('[bob] Then from Bob', $batch->content);
        $this->assertDatabaseCount('channel_conversation_participants', 2);
        $this->assertDatabaseHas('channel_conversation_participants', [
            'conversation_id' => $conversationId,
        ]);
    }
}
