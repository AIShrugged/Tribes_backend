<?php

namespace Tests\Unit;

use App\Enums\ChatRunStatus;
use App\Models\ChannelMessage;
use App\Models\Chat;
use App\Models\User;
use App\Services\Channel\ChannelBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatMessageStateTest extends TestCase
{
    use RefreshDatabase;

    private Chat $chat;

    private ChannelBus $channelBus;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->chat = Chat::create([
            'user_id' => $user->id,
            'title' => 'State test chat',
        ]);
        $this->channelBus = $this->app->make(ChannelBus::class);
    }

    #[Test]
    public function it_allows_valid_state_transitions(): void
    {
        $message = ChannelMessage::create([
            'conversation_id' => $this->channelBus->forChat($this->chat)->id,
            'role' => 'assistant',
            'status' => ChatRunStatus::QUEUED->value,
            'content' => 'Processing...',
        ]);

        $message->markProcessing(['current_attempt' => 1]);
        $this->assertSame(ChatRunStatus::PROCESSING, $message->fresh()->statusEnum());

        $message = $message->fresh();
        $message->markRetrying(['failure_code' => 'AI_REQUEST_FAILED']);
        $this->assertSame(ChatRunStatus::RETRYING, $message->fresh()->statusEnum());

        $message = $message->fresh();
        $message->markProcessing(['next_retry_at' => null]);
        $this->assertSame(ChatRunStatus::PROCESSING, $message->fresh()->statusEnum());

        $message = $message->fresh();
        $message->markCompleted(['content' => 'Done']);
        $this->assertSame(ChatRunStatus::COMPLETED, $message->fresh()->statusEnum());
    }

    #[Test]
    public function it_rejects_invalid_state_transitions(): void
    {
        $message = ChannelMessage::create([
            'conversation_id' => $this->channelBus->forChat($this->chat)->id,
            'role' => 'assistant',
            'status' => ChatRunStatus::COMPLETED->value,
            'content' => 'Done',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid channel message transition: completed -> processing');

        $message->markProcessing();
    }

    #[Test]
    public function it_exposes_status_progress_and_labels_from_enum(): void
    {
        $this->assertSame(5, ChatRunStatus::QUEUED->progressPercent());
        $this->assertSame(50, ChatRunStatus::PROCESSING->progressPercent());
        $this->assertSame(25, ChatRunStatus::RETRYING->progressPercent());
        $this->assertSame(100, ChatRunStatus::COMPLETED->progressPercent());
        $this->assertSame('Retrying after failure', ChatRunStatus::RETRYING->currentStepLabel());
        $this->assertNull(ChatRunStatus::FAILED->currentStepLabel());
    }
}
