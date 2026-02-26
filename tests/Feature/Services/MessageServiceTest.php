<?php

namespace Tests\Feature\Services;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Chat\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageServiceTest extends TestCase
{
    use RefreshDatabase;

    private MessageService $service;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service      = $this->app->make(MessageService::class);
        $this->conversation = Conversation::create(['channel_type' => ChannelType::Web]);
    }

    /** @test */
    public function it_creates_user_message_with_user_sender(): void
    {
        $user = User::factory()->create();

        $msg = $this->service->createUserMessage($this->conversation, $user, 'Hello!');

        $this->assertEquals('user', $msg->role);
        $this->assertEquals('Hello!', $msg->content);
        $this->assertEquals(User::class, $msg->sender_type);
        $this->assertEquals($user->id, $msg->sender_id);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'role'            => 'user',
            'content'         => 'Hello!',
        ]);
    }

    /** @test */
    public function it_creates_user_message_with_telegram_user_sender(): void
    {
        $telegramUser = TelegramUser::create(['telegram_user_id' => 77777]);

        $msg = $this->service->createUserMessage($this->conversation, $telegramUser, 'Привет!');

        $this->assertEquals('user', $msg->role);
        $this->assertEquals(TelegramUser::class, $msg->sender_type);
        $this->assertEquals($telegramUser->getKey(), $msg->sender_id);
    }

    /** @test */
    public function it_touches_conversation_updated_at_on_message_create(): void
    {
        $user    = User::factory()->create();
        $before  = now()->subMinute();
        $this->conversation->update(['updated_at' => $before]);

        $this->service->createUserMessage($this->conversation, $user, 'Test');

        $this->conversation->refresh();
        $this->assertTrue($this->conversation->updated_at->isAfter($before));
    }

    /** @test */
    public function it_creates_assistant_message_with_null_sender(): void
    {
        $msg = $this->service->createAssistantMessage($this->conversation, 'Bot response');

        $this->assertEquals('assistant', $msg->role);
        $this->assertEquals('Bot response', $msg->content);
        $this->assertNull($msg->sender_type);
        $this->assertNull($msg->sender_id);
    }

    /** @test */
    public function it_creates_assistant_message_stores_followup_in_metadata(): void
    {
        $followup = ['summary' => 'Meeting notes', 'actions' => ['task1']];

        $msg = $this->service->createAssistantMessage($this->conversation, 'Answer', $followup);

        $this->assertTrue($msg->hasFollowup());
        $this->assertEquals($followup, $msg->getFollowupData());
    }

    /** @test */
    public function it_gets_recent_history_in_chronological_order(): void
    {
        $user = User::factory()->create();
        $this->service->createUserMessage($this->conversation, $user, 'First');
        $this->service->createAssistantMessage($this->conversation, 'Second');
        $this->service->createUserMessage($this->conversation, $user, 'Third');

        $history = $this->service->getRecentHistory($this->conversation);

        $this->assertCount(3, $history);
        $this->assertEquals('First', $history->first()->content);
        $this->assertEquals('Third', $history->last()->content);
    }

    /** @test */
    public function it_respects_limit_in_recent_history(): void
    {
        $user = User::factory()->create();
        for ($i = 1; $i <= 5; $i++) {
            $this->service->createUserMessage($this->conversation, $user, "Message $i");
        }

        $history = $this->service->getRecentHistory($this->conversation, 3);

        $this->assertCount(3, $history);
        // Должны вернуть последние 3
        $this->assertEquals('Message 3', $history->first()->content);
        $this->assertEquals('Message 5', $history->last()->content);
    }

    /** @test */
    public function it_paginates_messages(): void
    {
        $user = User::factory()->create();
        for ($i = 1; $i <= 5; $i++) {
            $this->service->createUserMessage($this->conversation, $user, "Msg $i");
        }

        $page1 = $this->service->getMessages($this->conversation, 0, 3);
        $page2 = $this->service->getMessages($this->conversation, 3, 3);

        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    /** @test */
    public function it_counts_messages(): void
    {
        $user = User::factory()->create();
        $this->service->createUserMessage($this->conversation, $user, 'One');
        $this->service->createAssistantMessage($this->conversation, 'Two');

        $count = $this->service->countMessages($this->conversation);

        $this->assertEquals(2, $count);
    }
}
