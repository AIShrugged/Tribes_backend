<?php

namespace Tests\Feature;

use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TelegramChatRegistration;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class TelegramBotWebhookTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bot_added_and_attach_code_command_bind_the_chat(): void
    {
        config()->set('telegram.bot_username', 'wanda_test_bot');

        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager);

        TelegramUser::query()->create([
            'telegram_user_id' => 900001,
            'telegram_username' => 'manager_user',
            'user_id' => $manager->id,
        ]);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->twice()
            ->andReturnUsing(
                fn () => new Update($this->botAddedMyChatMemberPayload()),
                fn () => new Update($this->attachCommandPayload(
                    TelegramChatRegistration::query()
                        ->where('telegram_chat_id', 555123)
                        ->whereNull('message_thread_id')
                        ->firstOrFail()
                        ->attach_code
                )),
            );
        $telegramApi->shouldReceive('sendMessage')
            ->once()
            ->andReturnTrue();

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $registration = TelegramChatRegistration::query()
            ->where('telegram_chat_id', 555123)
            ->whereNull('message_thread_id')
            ->firstOrFail();

        $this->assertSame('Engineering Room', $registration->chat_title);
        $this->assertNull($registration->organization_id);

        $this->actingAs($manager)
            ->postJson("/api/v1/telegram/chats/{$registration->id}/attach-code", [
                'organization_id' => $organization->id,
                'team_id' => $team->id,
            ])->assertStatus(200);

        $registration->refresh();
        $this->assertNotNull($registration->attach_code);

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $registration->refresh();
        $conversation = $registration->conversation()->firstOrFail();

        $this->assertSame($organization->id, $conversation->organization_id);
        $this->assertSame($team->id, $conversation->team_id);
        $this->assertSame($manager->id, $conversation->user_id);
        $this->assertNotNull($registration->attach_code_used_at);
        $this->assertNotNull($registration->bound_at);
        $this->assertSame($manager->id, $registration->bound_by_user_id);
    }

    private function botAddedMyChatMemberPayload(): array
    {
        return [
            'update_id' => 1001,
            'my_chat_member' => [
                'date' => now()->timestamp,
                'chat' => [
                    'id' => 555123,
                    'type' => 'supergroup',
                    'title' => 'Engineering Room',
                ],
                'from' => [
                    'id' => 900001,
                    'is_bot' => false,
                    'username' => 'manager_user',
                    'first_name' => 'Manager',
                ],
                'old_chat_member' => [
                    'status' => 'left',
                    'user' => [
                        'id' => 777000,
                        'is_bot' => true,
                        'username' => 'wanda_test_bot',
                        'first_name' => 'Wanda',
                    ],
                ],
                'new_chat_member' => [
                    'status' => 'member',
                    'user' => [
                        'id' => 777000,
                        'is_bot' => true,
                        'username' => 'wanda_test_bot',
                        'first_name' => 'Wanda',
                    ],
                ],
            ],
        ];
    }

    private function attachCommandPayload(string $code): array
    {
        return [
            'update_id' => 1002,
            'message' => [
                'message_id' => 2,
                'date' => now()->timestamp,
                'text' => '/attach '.$code,
                'chat' => [
                    'id' => 555123,
                    'type' => 'supergroup',
                    'title' => 'Engineering Room',
                ],
                'from' => [
                    'id' => 900001,
                    'is_bot' => false,
                    'username' => 'manager_user',
                    'first_name' => 'Manager',
                ],
            ],
        ];
    }

    private function createTenantContextFor(User $user): array
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Acme Telegram Webhook',
            'slug' => 'acme-telegram-webhook',
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Platform',
            'slug' => 'platform-webhook',
        ]);

        $organization->users()->attach($user->id, ['role' => 'manager']);
        $team->users()->attach($user->id);

        return [$organization, $team];
    }
}
