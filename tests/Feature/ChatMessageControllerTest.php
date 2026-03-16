<?php

namespace Tests\Feature;

use App\Jobs\ProcessChatBranchJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
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
            'title' => 'Test Chat',
        ]);
    }

    // --- GET /api/v1/chats/{chat}/messages ---

    #[Test]
    public function it_requires_authentication_to_list_messages(): void
    {
        $response = $this->getJson("/api/v1/chats/{$this->chat->id}/messages");

        $response->assertStatus(401);
    }

    #[Test]
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

    #[Test]
    public function it_cannot_view_messages_of_another_users_chat(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->getJson("/api/v1/chats/{$this->chat->id}/messages");

        $response->assertStatus(404);
    }

    #[Test]
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

    #[Test]
    public function it_returns_message_fields_in_correct_format(): void
    {
        ChatMessage::create(['chat_id' => $this->chat->id, 'role' => 'user', 'content' => 'Test']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chats/{$this->chat->id}/messages");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'chat_id',
                    'role',
                    'status',
                    'content',
                    'followup_data',
                    'error_message',
                    'agent_run_uuid',
                    'completed_at',
                    'created_at',
                ]],
            ]);
    }

    // --- POST /api/v1/chats/{chat}/messages ---

    #[Test]
    public function it_requires_authentication_to_send_message(): void
    {
        $response = $this->postJson("/api/v1/chats/{$this->chat->id}/messages", [
            'content' => 'Hello',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function it_sends_message_and_gets_agent_response(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chats/{$this->chat->id}/messages", [
                'content' => 'Вопрос пользователя',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.content', 'Processing...')
            ->assertJsonPath('data.chat_id', $this->chat->id);

        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $this->chat->id,
            'role' => 'user',
            'content' => 'Вопрос пользователя',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $this->chat->id,
            'role' => 'assistant',
            'content' => 'Processing...',
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessChatBranchJob::class);
    }

    #[Test]
    public function it_returns_run_status_for_own_chat(): void
    {
        $runUuid = '11111111-1111-4111-8111-111111111111';

        $assistantMessage = ChatMessage::create([
            'chat_id' => $this->chat->id,
            'role' => 'assistant',
            'status' => 'processing',
            'content' => 'Processing...',
            'agent_run_uuid' => $runUuid,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chats/{$this->chat->id}/runs/{$runUuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.agent_run_uuid', $runUuid)
            ->assertJsonPath('data.chat_id', $this->chat->id)
            ->assertJsonPath('data.message_id', $assistantMessage->id)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.progress_percent', 50)
            ->assertJsonPath('data.current_step_label', 'Generating response')
            ->assertJsonPath('data.message.content', 'Processing...');
    }

    #[Test]
    public function it_cannot_view_run_status_of_another_users_chat(): void
    {
        $runUuid = '22222222-2222-4222-8222-222222222222';

        ChatMessage::create([
            'chat_id' => $this->chat->id,
            'role' => 'assistant',
            'status' => 'processing',
            'content' => 'Processing...',
            'agent_run_uuid' => $runUuid,
        ]);

        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->getJson("/api/v1/chats/{$this->chat->id}/runs/{$runUuid}");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_for_unknown_run_uuid(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chats/{$this->chat->id}/runs/missing-run");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Run not found');
    }

    #[Test]
    public function it_cannot_send_message_to_another_users_chat(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->postJson("/api/v1/chats/{$this->chat->id}/messages", [
                'content' => 'Hello',
            ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function it_validates_content_is_required(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chats/{$this->chat->id}/messages", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    #[Test]
    public function it_validates_content_max_length(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chats/{$this->chat->id}/messages", [
                'content' => str_repeat('a', 10001),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    #[Test]
    public function it_returns_404_for_nonexistent_chat(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/chats/99999/messages', [
                'content' => 'Hello',
            ]);

        $response->assertStatus(404);
    }

    // --- GET /api/v1/chats ---

    #[Test]
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

    #[Test]
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
            'title' => 'Мой новый чат',
        ]);
    }

    #[Test]
    public function it_requires_authentication_to_list_chats(): void
    {
        $response = $this->getJson('/api/v1/chats');

        $response->assertStatus(401);
    }
}
