<?php

namespace Tests\Feature;

use App\Models\ChannelConversation;
use App\Models\ChannelIdentity;
use App\Models\Chat;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Agent\Tools\SendUserMessageTool;
use App\Services\Channel\ChannelBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Api;
use Tests\TestCase;

class SendUserMessageToolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_sends_message_to_latest_web_chat_for_current_user(): void
    {
        $user = User::factory()->create();
        $chat = Chat::create([
            'user_id' => $user->id,
            'title' => 'Primary chat',
        ]);

        $tool = $this->app->make(SendUserMessageTool::class, ['user' => $user]);

        $result = $tool->execute([
            'channel' => 'web_chat',
            'content' => 'Reminder from agent',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('web_chat', data_get($result, 'conversation.channel_type'));
        $this->assertSame($chat->id, data_get($result, 'conversation.chat_id'));
        $this->assertSame('Reminder from agent', data_get($result, 'message.content'));
        $this->assertDatabaseHas('channel_messages', [
            'conversation_id' => app(ChannelBus::class)->forChat($chat)->id,
            'role' => 'assistant',
            'content' => 'Reminder from agent',
        ]);
    }

    #[Test]
    public function it_sends_message_to_latest_linked_telegram_conversation(): void
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Platform',
            'slug' => 'platform',
        ]);

        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'employee']);
        $team->users()->attach($user->id);

        $telegramUser = TelegramUser::create([
            'telegram_user_id' => 123456,
            'telegram_username' => 'linked_user',
            'user_id' => $user->id,
        ]);

        $conversation = app(ChannelBus::class)->forTelegram(98765);
        $identity = ChannelIdentity::create([
            'channel_type' => 'telegram',
            'external_id' => (string) $telegramUser->telegram_user_id,
            'user_id' => $user->id,
            'display_name' => 'linked_user',
            'username' => 'linked_user',
        ]);
        $conversation->participants()->create([
            'channel_identity_id' => $identity->id,
            'joined_at' => now(),
            'last_message_at' => now(),
        ]);
        $conversation->forceFill(['latest_message_at' => now()])->save();

        $apiMock = Mockery::mock('overload:'.Api::class);
        $apiMock->shouldReceive('__construct')->andReturnNull();
        $apiMock->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool => (int) $payload['chat_id'] === 98765 && $payload['text'] === 'Telegram reminder'))
            ->andReturn((object) ['message_id' => 1]);

        $tool = $this->app->make(SendUserMessageTool::class, ['user' => $user]);

        $result = $tool->execute([
            'channel' => 'telegram',
            'content' => 'Telegram reminder',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('telegram', data_get($result, 'conversation.channel_type'));
        $this->assertSame(98765, data_get($result, 'conversation.telegram_chat_id'));
        $this->assertDatabaseHas('channel_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Telegram reminder',
        ]);
    }

    #[Test]
    public function it_can_create_telegram_target_from_explicit_chat_id_when_user_is_linked(): void
    {
        $user = User::factory()->create();

        TelegramUser::create([
            'telegram_user_id' => 123456,
            'telegram_username' => 'linked_user',
            'user_id' => $user->id,
        ]);

        $apiMock = Mockery::mock('overload:'.Api::class);
        $apiMock->shouldReceive('__construct')->andReturnNull();
        $apiMock->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool => (int) $payload['chat_id'] === 1024736013 && $payload['text'] === 'Created from explicit chat id'))
            ->andReturn((object) ['message_id' => 1]);

        $tool = $this->app->make(SendUserMessageTool::class, ['user' => $user]);

        $result = $tool->execute([
            'channel' => 'telegram',
            'content' => 'Created from explicit chat id',
            'telegram_chat_id' => 1024736013,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1024736013, data_get($result, 'conversation.telegram_chat_id'));
        $this->assertDatabaseHas('channel_conversations', [
            'channel_type' => 'telegram',
            'telegram_chat_id' => 1024736013,
        ]);
        $this->assertDatabaseHas('channel_messages', [
            'role' => 'assistant',
            'content' => 'Created from explicit chat id',
        ]);
    }

    #[Test]
    public function it_retries_telegram_delivery_without_markdown_when_entity_parsing_fails(): void
    {
        $user = User::factory()->create();

        TelegramUser::create([
            'telegram_user_id' => 123456,
            'telegram_username' => 'linked_user',
            'user_id' => $user->id,
        ]);

        $apiMock = Mockery::mock('overload:'.Api::class);
        $apiMock->shouldReceive('__construct')->andReturnNull();
        $apiMock->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool => ($payload['parse_mode'] ?? null) === 'Markdown'))
            ->andThrow(new \RuntimeException('Bad Request: can\'t parse entities: Can\'t find end of the entity starting at byte offset 262'));
        $apiMock->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool => ! array_key_exists('parse_mode', $payload) && (int) $payload['chat_id'] === 1024736013))
            ->andReturn((object) ['message_id' => 1]);

        $tool = $this->app->make(SendUserMessageTool::class, ['user' => $user]);

        $result = $tool->execute([
            'channel' => 'telegram',
            'content' => 'Problematic **markdown',
            'telegram_chat_id' => 1024736013,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1024736013, data_get($result, 'conversation.telegram_chat_id'));
    }

    #[Test]
    public function it_rejects_send_when_user_has_no_target_conversation(): void
    {
        $user = User::factory()->create();
        $tool = $this->app->make(SendUserMessageTool::class, ['user' => $user]);

        $result = $tool->execute([
            'channel' => 'web_chat',
            'content' => 'Hello',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('No eligible conversation found for the requested channel.', $result['error']);
    }
}
