<?php

namespace Tests\Feature;

use App\Jobs\ProcessChatBranchJob;
use App\Models\ChannelMessage;
use App\Models\Chat;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Channel\ChannelBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatMessageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Chat $chat;

    protected Organization $organization;

    protected Team $team;

    protected ChannelBus $channelBus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        [$this->organization, $this->team] = $this->createTenantContextFor($this->user);
        $this->chat = Chat::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'team_id' => $this->team->id,
            'title' => 'Test Chat',
        ]);
        $this->channelBus = $this->app->make(ChannelBus::class);
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
        $this->channelBus->createChatUserMessage($this->chat, 'Hello');
        $this->channelBus->createChatAssistantMessage($this->chat, 'Hi there');

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
        $this->channelBus->createChatUserMessage($this->chat, 'First');
        $this->channelBus->createChatAssistantMessage($this->chat, 'Second');
        $this->channelBus->createChatUserMessage($this->chat, 'Third');

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
        $this->channelBus->createChatUserMessage($this->chat, 'Test');

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
                    'failure_code',
                    'agent_run_uuid',
                    'current_attempt',
                    'max_attempts',
                    'completed_at',
                    'next_retry_at',
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
            ->assertJsonPath('data.chat_id', $this->chat->id)
            ->assertJsonPath('data.current_attempt', 0)
            ->assertJsonPath('data.max_attempts', 3);

        $conversationId = $this->channelBus->forChat($this->chat)->id;

        $this->assertDatabaseHas('channel_messages', [
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => 'Вопрос пользователя',
        ]);
        $this->assertDatabaseHas('channel_messages', [
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => 'Processing...',
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessChatBranchJob::class);
    }

    #[Test]
    public function it_persists_page_context_metadata_with_the_user_message(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chats/{$this->chat->id}/messages", [
                'content' => 'Что здесь важно?',
                'page_title' => 'Dashboard',
                'page_url' => 'https://app.example.com/dashboard',
                'page_html' => '<html><body><h1>Dashboard</h1><p>Open issues</p></body></html>',
            ]);

        $response->assertStatus(200);

        $conversationId = $this->channelBus->forChat($this->chat)->id;

        $this->assertDatabaseHas('channel_messages', [
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => 'Что здесь важно?',
        ]);

        $message = ChannelMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Dashboard', data_get($message->metadata, 'page_context.title'));
        $this->assertSame('https://app.example.com/dashboard', data_get($message->metadata, 'page_context.url'));
        $this->assertStringContainsString('Dashboard', (string) data_get($message->metadata, 'page_context.text'));
    }

    #[Test]
    public function it_returns_run_status_for_own_chat(): void
    {
        $runUuid = '11111111-1111-4111-8111-111111111111';

        $assistantMessage = ChannelMessage::create([
            'conversation_id' => $this->channelBus->forChat($this->chat)->id,
            'role' => 'assistant',
            'status' => 'processing',
            'content' => 'Processing...',
            'agent_run_uuid' => $runUuid,
            'current_attempt' => 1,
            'max_attempts' => 3,
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
            ->assertJsonPath('data.current_attempt', 1)
            ->assertJsonPath('data.max_attempts', 3)
            ->assertJsonPath('data.message.content', 'Processing...');
    }

    #[Test]
    public function it_returns_retry_metadata_for_retrying_run(): void
    {
        $runUuid = '33333333-3333-4333-8333-333333333333';
        $nextRetryAt = Carbon::parse('2026-03-16 12:00:00');

        ChannelMessage::create([
            'conversation_id' => $this->channelBus->forChat($this->chat)->id,
            'role' => 'assistant',
            'status' => 'retrying',
            'content' => 'Processing...',
            'agent_run_uuid' => $runUuid,
            'current_attempt' => 1,
            'max_attempts' => 3,
            'failure_code' => 'AI_REQUEST_FAILED',
            'error_message' => 'Temporary upstream error',
            'next_retry_at' => $nextRetryAt,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/chats/{$this->chat->id}/runs/{$runUuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'retrying')
            ->assertJsonPath('data.progress_percent', 25)
            ->assertJsonPath('data.current_step_label', 'Retrying after failure')
            ->assertJsonPath('data.current_attempt', 1)
            ->assertJsonPath('data.max_attempts', 3)
            ->assertJsonPath('data.failure_code', 'AI_REQUEST_FAILED')
            ->assertJsonPath('data.error_message', 'Temporary upstream error');
    }

    #[Test]
    public function it_cannot_view_run_status_of_another_users_chat(): void
    {
        $runUuid = '22222222-2222-4222-8222-222222222222';

        ChannelMessage::create([
            'conversation_id' => $this->channelBus->forChat($this->chat)->id,
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
    public function it_allows_messages_for_unbound_personal_chat(): void
    {
        Queue::fake();

        $unboundChat = Chat::create([
            'user_id' => $this->user->id,
            'title' => 'Unbound Chat',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/chats/{$unboundChat->id}/messages", [
                'content' => 'Hello',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.status', 'queued');

        $conversationId = $this->channelBus->forChat($unboundChat)->id;

        $this->assertDatabaseHas('channel_messages', [
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => 'Hello',
        ]);

        Queue::assertPushed(ProcessChatBranchJob::class);
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

    private function createTenantContextFor(User ...$users): array
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Acme Chat',
            'slug' => 'acme-chat',
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Support',
            'slug' => 'support',
        ]);

        foreach ($users as $user) {
            $organization->users()->attach($user->id, ['role' => 'employee']);
            $team->users()->attach($user->id);
        }

        return [$organization, $team];
    }
}
