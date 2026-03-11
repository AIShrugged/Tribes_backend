<?php

namespace Tests\Feature\Http;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Agent\Tools\GetChatHistoryTool;
use App\Services\Chat\ConversationService;
use App\Services\Chat\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class TelegramBotControllerTest extends TestCase
{
    use RefreshDatabase;

    private Api $mockTelegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTelegram = Mockery::mock(Api::class);
        $this->mockTelegram->shouldReceive('sendMessage')->andReturn(new \Telegram\Bot\Objects\Message([]));
        $this->app->instance(Api::class, $this->mockTelegram);

        // Clear whitelist so test user IDs are always allowed
        config(['telegram.allowed_users' => '']);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message'       => ['role' => 'assistant', 'content' => 'Bot response'],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);
    }

    private function makeUpdate(array $messageData): Update
    {
        return new Update([
            'update_id' => random_int(1, 99999),
            'message'   => array_merge([
                'message_id' => random_int(1, 99999),
            ], $messageData),
        ]);
    }

    private function privateMessageUpdate(int $userId, string $text, string $username = 'testuser'): Update
    {
        return $this->makeUpdate([
            'from' => ['id' => $userId, 'username' => $username, 'is_bot' => false],
            'chat' => ['id' => $userId, 'type' => 'private'],
            'text' => $text,
        ]);
    }

    private function groupMessageUpdate(int $userId, int $groupId, string $text, string $username = 'testuser'): Update
    {
        return $this->makeUpdate([
            'from' => ['id' => $userId, 'username' => $username, 'is_bot' => false],
            'chat' => ['id' => $groupId, 'type' => 'group'],
            'text' => $text,
        ]);
    }

    private function postWebhook(Update $update): \Illuminate\Testing\TestResponse
    {
        $this->mockTelegram->shouldReceive('getWebhookUpdate')->once()->andReturn($update);

        return $this->postJson('/api/v1/telegram/webhook');
    }

    private function makeLinkedUser(int $telegramUserId): array
    {
        $user         = User::factory()->create();
        $telegramUser = TelegramUser::create([
            'telegram_user_id'  => $telegramUserId,
            'telegram_username' => 'user' . $telegramUserId,
            'user_id'           => $user->id,
        ]);

        return [$user, $telegramUser];
    }

    // --- Conversation creation ---

    /** @test */
    public function it_creates_telegram_private_conversation_on_first_message(): void
    {
        [$user, $telegramUser] = $this->makeLinkedUser(11111);
        $update                = $this->privateMessageUpdate(11111, 'Hello bot');

        $this->postWebhook($update)->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'channel_type' => ChannelType::TelegramPrivate->value,
            'external_id'  => '11111',
        ]);
    }

    /** @test */
    public function it_creates_telegram_group_conversation_on_first_message(): void
    {
        [$user, $telegramUser] = $this->makeLinkedUser(22222);
        $update                = $this->groupMessageUpdate(22222, -100123456, '@wandabot Hello');

        $this->postWebhook($update)->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'channel_type' => ChannelType::TelegramGroup->value,
            'external_id'  => '-100123456',
        ]);
    }

    /** @test */
    public function it_reuses_existing_conversation_for_same_chat(): void
    {
        [$user, $telegramUser] = $this->makeLinkedUser(33333);
        $update1               = $this->privateMessageUpdate(33333, 'First message');
        $update2               = $this->privateMessageUpdate(33333, 'Second message');

        $this->mockTelegram->shouldReceive('getWebhookUpdate')->andReturn($update1, $update2);

        $this->postJson('/api/v1/telegram/webhook');
        $this->postJson('/api/v1/telegram/webhook');

        $this->assertDatabaseCount('conversations', 1);
    }

    /** @test */
    public function it_adds_telegram_user_as_participant(): void
    {
        [$user, $telegramUser] = $this->makeLinkedUser(44444);
        $update                = $this->privateMessageUpdate(44444, 'Hello');

        $this->postWebhook($update);

        $conversation = Conversation::where('external_id', '44444')->first();
        $this->assertNotNull($conversation);
        $this->assertTrue($conversation->hasParticipant($telegramUser));
    }

    // --- Message saving ---

    /** @test */
    public function it_saves_user_message_to_messages_table(): void
    {
        [$user, $telegramUser] = $this->makeLinkedUser(55555);
        $update                = $this->privateMessageUpdate(55555, 'Save this message');

        $this->postWebhook($update);

        $this->assertDatabaseHas('messages', [
            'role'    => 'user',
            'content' => 'Save this message',
        ]);
    }

    /** @test */
    public function it_saves_bot_response_to_messages_table(): void
    {
        [$user, $telegramUser] = $this->makeLinkedUser(66666);
        $update                = $this->privateMessageUpdate(66666, 'Hello');

        $this->postWebhook($update);

        $this->assertDatabaseHas('messages', [
            'role'    => 'assistant',
            'content' => 'Bot response',
        ]);
    }

    /** @test */
    public function it_does_not_save_to_telegram_chat_messages_table(): void
    {
        [$user, $telegramUser] = $this->makeLinkedUser(77777);
        $update                = $this->privateMessageUpdate(77777, 'Hello');

        $this->postWebhook($update);

        $this->assertDatabaseCount('telegram_chat_messages', 0);
    }

    /** @test */
    public function it_skips_processing_when_user_has_no_linked_account(): void
    {
        // TelegramUser without linked User
        TelegramUser::create(['telegram_user_id' => 88888]);
        $update = $this->privateMessageUpdate(88888, 'Hello bot');

        $this->postWebhook($update)->assertStatus(200);

        // User message is always saved; processing stops because there's no linked User account
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', ['role' => 'user', 'content' => 'Hello bot']);
    }

    // --- GetChatHistoryTool ---

    /** @test */
    public function get_chat_history_tool_queries_messages_table(): void
    {
        $conversation = Conversation::create([
            'channel_type' => ChannelType::TelegramPrivate,
            'external_id'  => '99999',
        ]);

        $messageService = $this->app->make(MessageService::class);
        $telegramUser   = TelegramUser::create(['telegram_user_id' => 99999]);
        $messageService->createUserMessage($conversation, $telegramUser, 'History message');
        $messageService->createAssistantMessage($conversation, 'History response');

        $tool   = new GetChatHistoryTool($conversation);
        $result = $tool->execute(['limit' => 10]);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['count']);
        $this->assertEquals('History message', $result['messages'][0]['content']);
        $this->assertEquals('History response', $result['messages'][1]['content']);
    }
}
