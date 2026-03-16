<?php

namespace Tests\Feature;

use App\Models\TelegramChatMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Agent\TelegramMessageCoalescer;
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

        TelegramChatMessage::create([
            'telegram_chat_id' => 77,
            'telegram_user_id' => $telegramUser->telegram_user_id,
            'role' => 'user',
            'content' => 'First message',
        ]);

        TelegramChatMessage::create([
            'telegram_chat_id' => 77,
            'telegram_user_id' => $telegramUser->telegram_user_id,
            'role' => 'user',
            'content' => 'Second message',
        ]);

        $batch = app(TelegramMessageCoalescer::class)->claimPendingBatch(77);

        $this->assertNotNull($batch);
        $this->assertCount(2, $batch->messages);
        $this->assertStringContainsString('1. First message', $batch->content);
        $this->assertStringContainsString('2. Second message', $batch->content);

        $this->assertDatabaseCount('telegram_chat_messages', 2);
        $this->assertDatabaseHas('telegram_chat_messages', [
            'telegram_chat_id' => 77,
            'content' => 'First message',
            'agent_batch_uuid' => $batch->batchUuid,
        ]);
    }
}
