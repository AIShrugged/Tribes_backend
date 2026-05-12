<?php

namespace Tests\Feature;

use App\Models\TelegramLinkToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class TelegramLinkTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Controller: POST /api/v1/telegram/link
    // -------------------------------------------------------------------------

    #[Test]
    public function generate_link_requires_auth(): void
    {
        $this->postJson('/api/v1/telegram/link')
            ->assertUnauthorized();
    }

    #[Test]
    public function generate_link_returns_deep_link_url(): void
    {
        config()->set('telegram.bot_username', 'spodial_test_bot');

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/telegram/link')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['link_url', 'expires_at'],
            ]);

        $linkUrl = $response->json('data.link_url');
        $this->assertStringStartsWith('https://t.me/spodial_test_bot?start=', $linkUrl);

        $token = Str::after($linkUrl, '?start=');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{32}$/', $token);

        $this->assertDatabaseHas('telegram_link_tokens', [
            'user_id' => $user->id,
            'token' => $token,
        ]);
    }

    #[Test]
    public function generate_link_invalidates_previous_unused_tokens(): void
    {
        $user = User::factory()->create();

        $old = TelegramLinkToken::create([
            'user_id' => $user->id,
            'token' => Str::random(32),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/telegram/link')
            ->assertOk();

        $this->assertDatabaseMissing('telegram_link_tokens', ['id' => $old->id]);
        $this->assertDatabaseCount('telegram_link_tokens', 1);
    }

    #[Test]
    public function generate_link_does_not_delete_used_tokens(): void
    {
        $user = User::factory()->create();

        $used = TelegramLinkToken::create([
            'user_id' => $user->id,
            'token' => Str::random(32),
            'expires_at' => now()->addMinutes(10),
            'used_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/telegram/link')
            ->assertOk();

        $this->assertDatabaseHas('telegram_link_tokens', ['id' => $used->id]);
        $this->assertDatabaseCount('telegram_link_tokens', 2);
    }

    // -------------------------------------------------------------------------
    // Webhook: /start {token}
    // -------------------------------------------------------------------------

    #[Test]
    public function start_command_links_telegram_account(): void
    {
        $user = User::factory()->create();
        $token = Str::random(32);

        TelegramLinkToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addMinutes(10),
        ]);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->startCommandPayload($token, 111222, 'new_tg_user')));
        $telegramApi->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(fn ($p) => str_contains($p['text'], 'успешно привязан')))
            ->andReturnTrue();

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_users', [
            'telegram_user_id' => 111222,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('telegram_link_tokens', [
            'token' => $token,
        ]);

        $linkToken = TelegramLinkToken::where('token', $token)->first();
        $this->assertNotNull($linkToken->used_at);
    }

    #[Test]
    public function start_command_with_expired_token_sends_error(): void
    {
        $user = User::factory()->create();
        $token = Str::random(32);

        TelegramLinkToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->subMinute(),
        ]);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->startCommandPayload($token, 111333)));
        $telegramApi->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(fn ($p) => str_contains($p['text'], 'истёк')))
            ->andReturnTrue();

        $this->postJson('/api/v1/telegram/webhook')->assertOk();

        $this->assertDatabaseMissing('telegram_users', ['telegram_user_id' => 111333, 'user_id' => $user->id]);
    }

    #[Test]
    public function start_command_with_used_token_sends_error(): void
    {
        $user = User::factory()->create();
        $token = Str::random(32);

        TelegramLinkToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addMinutes(10),
            'used_at' => now()->subMinute(),
        ]);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->startCommandPayload($token, 111444)));
        $telegramApi->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(fn ($p) => str_contains($p['text'], 'уже была использована')))
            ->andReturnTrue();

        $this->postJson('/api/v1/telegram/webhook')->assertOk();
    }

    #[Test]
    public function start_command_with_invalid_token_sends_error(): void
    {
        $token = Str::random(32);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->startCommandPayload($token, 111555)));
        $telegramApi->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(fn ($p) => str_contains($p['text'], 'недействительна')))
            ->andReturnTrue();

        $this->postJson('/api/v1/telegram/webhook')->assertOk();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function startCommandPayload(string $token, int $fromId, string $username = 'tg_user'): array
    {
        return [
            'update_id' => rand(10000, 99999),
            'message' => [
                'message_id' => rand(1, 9999),
                'date' => now()->timestamp,
                'text' => '/start '.$token,
                'chat' => [
                    'id' => $fromId,
                    'type' => 'private',
                ],
                'from' => [
                    'id' => $fromId,
                    'is_bot' => false,
                    'username' => $username,
                    'first_name' => 'Test',
                ],
            ],
        ];
    }
}
