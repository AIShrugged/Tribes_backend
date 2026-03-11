<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ConversationService;
use App\Services\Chat\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatMessageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Conversation $conversation;
    protected ConversationService $conversationService;
    protected MessageService $messageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user                = User::factory()->create();
        $this->conversationService = $this->app->make(ConversationService::class);
        $this->messageService      = $this->app->make(MessageService::class);
        $this->conversation        = $this->conversationService->createWebConversation($this->user, 'Test Chat');
    }

    // --- GET /api/v1/chats/{chat}/messages ---

    /** @test */
    public function it_requires_authentication_to_list_messages(): void
    {
        $response = $this->getJson("/api/v1/chats/{$this->conversation->id}/messages");

        $response->assertStatus(401);
    }

    /** @test */
    public function it_returns_messages_for_own_chat(): void
    {
        $this->messageService->createUserMessage($this->conversation, $this->user, 'Hello');
        $this->messageService->createAssistantMessage($this->conversation, 'Hi there');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chats/{$this->conversation->id}/messages");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.role', 'user')
            ->assertJsonPath('data.0.content', 'Hello')
            ->assertJsonPath('data.1.role', 'assistant')
            ->assertJsonPath('data.1.content', 'Hi there');
    }

    /** @test */
    public function it_cannot_view_messages_of_another_users_chat(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->getJson("/api/v1/chats/{$this->conversation->id}/messages");

        $response->assertStatus(404);
    }

    /** @test */
    public function it_returns_messages_in_chronological_order(): void
    {
        $this->messageService->createUserMessage($this->conversation, $this->user, 'First');
        $this->messageService->createAssistantMessage($this->conversation, 'Second');
        $this->messageService->createUserMessage($this->conversation, $this->user, 'Third');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chats/{$this->conversation->id}/messages");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('First', $data[0]['content']);
        $this->assertEquals('Second', $data[1]['content']);
        $this->assertEquals('Third', $data[2]['content']);
    }

    /** @test */
    public function it_returns_message_fields_in_correct_format(): void
    {
        $this->messageService->createUserMessage($this->conversation, $this->user, 'Test');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chats/{$this->conversation->id}/messages");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'chat_id', 'role', 'content', 'followup_data', 'created_at']],
            ]);
    }

    // --- POST /api/v1/chats/{chat}/messages ---

    /** @test */
    public function it_requires_authentication_to_send_message(): void
    {
        $response = $this->postJson("/api/v1/chats/{$this->conversation->id}/messages", [
            'content' => 'Hello',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function it_sends_message_and_gets_agent_response(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role'    => 'assistant',
                        'content' => 'Это ответ агента',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chats/{$this->conversation->id}/messages", [
                'content' => 'Вопрос пользователя',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'Это ответ агента')
            ->assertJsonPath('data.chat_id', $this->conversation->id);

        // Оба сообщения сохранены в messages
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'role'            => 'user',
            'content'         => 'Вопрос пользователя',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'role'            => 'assistant',
            'content'         => 'Это ответ агента',
        ]);
    }

    /** @test */
    public function it_cannot_send_message_to_another_users_chat(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->postJson("/api/v1/chats/{$this->conversation->id}/messages", [
                'content' => 'Hello',
            ]);

        $response->assertStatus(404);
    }

    /** @test */
    public function it_validates_content_is_required(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chats/{$this->conversation->id}/messages", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    /** @test */
    public function it_validates_content_max_length(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chats/{$this->conversation->id}/messages", [
                'content' => str_repeat('a', 10001),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    /** @test */
    public function it_returns_404_for_nonexistent_chat(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/chats/99999/messages', [
                'content' => 'Hello',
            ]);

        $response->assertStatus(404);
    }

    // --- GET /api/v1/chats ---

    /** @test */
    public function it_returns_chat_list_for_authenticated_user(): void
    {
        $this->conversationService->createWebConversation($this->user, 'Chat 1');
        $this->conversationService->createWebConversation($this->user, 'Chat 2');

        // Чужой чат — не должен попасть в список
        $otherUser = User::factory()->create();
        $this->conversationService->createWebConversation($otherUser, 'Other chat');

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/chats');

        $response->assertStatus(200);

        $data = $response->json('data');
        // Пользователь видит только свои чаты (созданный в setUp + 2 новых = 3)
        $this->assertCount(3, $data);
    }

    /** @test */
    public function it_creates_a_new_chat(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/chats', [
                'title' => 'Мой новый чат',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Мой новый чат');

        $this->assertDatabaseHas('conversations', [
            'channel_type' => ChannelType::Web->value,
            'title'        => 'Мой новый чат',
        ]);
    }

    /** @test */
    public function it_requires_authentication_to_list_chats(): void
    {
        $response = $this->getJson('/api/v1/chats');

        $response->assertStatus(401);
    }
}
