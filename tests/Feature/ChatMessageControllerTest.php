<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatMessageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Chat $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->chat = Chat::create([
            'user_id' => $this->user->id,
            'title'   => 'Test Chat',
        ]);
    }

    // --- GET /api/v1/chats/{chat}/messages ---

    /** @test */
    public function it_requires_authentication_to_list_messages(): void
    {
        $response = $this->getJson("/api/v1/chats/{$this->chat->id}/messages");

        $response->assertStatus(401);
    }

    /** @test */
    public function it_returns_messages_for_own_chat(): void
    {
        ChatMessage::create(['chat_id' => $this->chat->id, 'role' => 'user', 'content' => 'Hello']);
        ChatMessage::create(['chat_id' => $this->chat->id, 'role' => 'assistant', 'content' => 'Hi there']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chats/{$this->chat->id}/messages");

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
            ->getJson("/api/v1/chats/{$this->chat->id}/messages");

        $response->assertStatus(404);
    }

    /** @test */
    public function it_returns_messages_in_chronological_order(): void
    {
        ChatMessage::create(['chat_id' => $this->chat->id, 'role' => 'user', 'content' => 'First']);
        ChatMessage::create(['chat_id' => $this->chat->id, 'role' => 'assistant', 'content' => 'Second']);
        ChatMessage::create(['chat_id' => $this->chat->id, 'role' => 'user', 'content' => 'Third']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chats/{$this->chat->id}/messages");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('First', $data[0]['content']);
        $this->assertEquals('Second', $data[1]['content']);
        $this->assertEquals('Third', $data[2]['content']);
    }

    /** @test */
    public function it_returns_message_fields_in_correct_format(): void
    {
        ChatMessage::create(['chat_id' => $this->chat->id, 'role' => 'user', 'content' => 'Test']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chats/{$this->chat->id}/messages");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'chat_id', 'role', 'content', 'followup_data', 'created_at']],
            ]);
    }

    // --- POST /api/v1/chats/{chat}/messages ---

    /** @test */
    public function it_requires_authentication_to_send_message(): void
    {
        $response = $this->postJson("/api/v1/chats/{$this->chat->id}/messages", [
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
            ->postJson("/api/v1/chats/{$this->chat->id}/messages", [
                'content' => 'Вопрос пользователя',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'Это ответ агента')
            ->assertJsonPath('data.chat_id', $this->chat->id);

        // Оба сообщения сохранены в БД
        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $this->chat->id,
            'role'    => 'user',
            'content' => 'Вопрос пользователя',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $this->chat->id,
            'role'    => 'assistant',
            'content' => 'Это ответ агента',
        ]);
    }

    /** @test */
    public function it_cannot_send_message_to_another_users_chat(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->postJson("/api/v1/chats/{$this->chat->id}/messages", [
                'content' => 'Hello',
            ]);

        $response->assertStatus(404);
    }

    /** @test */
    public function it_validates_content_is_required(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chats/{$this->chat->id}/messages", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    /** @test */
    public function it_validates_content_max_length(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chats/{$this->chat->id}/messages", [
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
        Chat::create(['user_id' => $this->user->id, 'title' => 'Chat 1']);
        Chat::create(['user_id' => $this->user->id, 'title' => 'Chat 2']);

        // Чужой чат — не должен попасть в список
        $otherUser = User::factory()->create();
        Chat::create(['user_id' => $otherUser->id, 'title' => 'Other chat']);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/chats');

        $response->assertStatus(200);

        $data = $response->json('data');
        // Пользователь видит только свои чаты (изначально создан 1 в setUp + 2 новых = 3)
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

        $this->assertDatabaseHas('chats', [
            'user_id' => $this->user->id,
            'title'   => 'Мой новый чат',
        ]);
    }

    /** @test */
    public function it_requires_authentication_to_list_chats(): void
    {
        $response = $this->getJson('/api/v1/chats');

        $response->assertStatus(401);
    }
}
