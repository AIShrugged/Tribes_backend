<?php

namespace Tests\Feature;

use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TelegramChatRegistration;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Telegram\Bot\Objects\Update;
use Tests\TestCase;

class TelegramBotWebhookTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bot_added_to_pre_registered_group_becomes_bound(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager);

        $this->actingAs($manager)
            ->postJson('/api/v1/telegram/chats', [
                'name' => 'Engineering Room',
                'telegram_chat_id' => 555123,
                'organization_id' => $organization->id,
                'team_id' => $team->id,
            ])->assertStatus(200);

        $registration = TelegramChatRegistration::query()
            ->where('telegram_chat_id', 555123)
            ->firstOrFail();

        $this->assertNull($registration->bound_at);
        $this->assertNull($registration->channel_conversation_id);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->botAddedMyChatMemberPayload()));

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $registration->refresh();
        $this->assertNotNull($registration->bound_at);
        $this->assertNotNull($registration->channel_conversation_id);
        $this->assertSame('supergroup', $registration->chat_type);
    }

    #[Test]
    public function pre_registering_already_discovered_group_binds_immediately(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->botAddedMyChatMemberPayload()));

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk();

        $registration = TelegramChatRegistration::query()
            ->where('telegram_chat_id', 555123)
            ->firstOrFail();

        $this->assertNull($registration->organization_id);
        $this->assertNull($registration->bound_at);
        $this->assertNotNull($registration->channel_conversation_id);

        $this->actingAs($manager)
            ->postJson('/api/v1/telegram/chats', [
                'name' => 'Engineering Room',
                'telegram_chat_id' => 555123,
                'organization_id' => $organization->id,
                'team_id' => $team->id,
            ])->assertStatus(200);

        $registration->refresh();
        $this->assertNotNull($registration->bound_at);
        $this->assertSame($organization->id, $registration->organization_id);
        $this->assertSame('Engineering Room', $registration->chat_title);
    }

    #[Test]
    public function bot_added_to_unregistered_group_is_saved_but_not_bound(): void
    {
        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->botAddedMyChatMemberPayload()));

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk();

        $registration = TelegramChatRegistration::query()
            ->where('telegram_chat_id', 555123)
            ->firstOrFail();

        $this->assertNull($registration->organization_id);
        $this->assertNull($registration->bound_at);
        $this->assertNotNull($registration->channel_conversation_id);
    }

    #[Test]
    public function messages_in_unbound_group_are_ignored(): void
    {
        TelegramUser::query()->create([
            'telegram_user_id' => 900001,
            'telegram_username' => 'manager_user',
        ]);

        config()->set('telegram.bot_username', 'wanda_test_bot');

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->groupMessagePayload('@wanda_test_bot hello')));
        $telegramApi->shouldNotReceive('sendMessage');

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk();

        $this->assertDatabaseMissing('channel_messages', ['role' => 'user']);
    }

    #[Test]
    public function forum_topic_created_message_saves_topic_title(): void
    {
        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->forumTopicCreatedPayload()));
        $telegramApi->shouldNotReceive('sendMessage');

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $registration = TelegramChatRegistration::query()
            ->where('telegram_chat_id', 555123)
            ->where('message_thread_id', 336)
            ->firstOrFail();

        $this->assertSame('Backend Focus', $registration->conversation->title);
        $this->assertSame('supergroup', $registration->chat_type);
    }

    #[Test]
    public function bot_removed_from_group_unbinds_the_chat(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->twice()
            ->andReturnUsing(
                fn () => new Update($this->botAddedMyChatMemberPayload()),
                fn () => new Update($this->botRemovedMyChatMemberPayload()),
            );

        $this->postJson('/api/v1/telegram/webhook')->assertOk();

        $registration = TelegramChatRegistration::query()
            ->where('telegram_chat_id', 555123)
            ->firstOrFail();

        $this->actingAs($manager)->postJson('/api/v1/telegram/chats', [
            'name' => 'Engineering Room',
            'telegram_chat_id' => 555123,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
        ])->assertStatus(200);

        $registration->refresh();
        $this->assertNotNull($registration->bound_at);

        $this->postJson('/api/v1/telegram/webhook')->assertOk();

        $registration->refresh();
        $this->assertNull($registration->bound_at);
        $this->assertNotNull($registration->channel_conversation_id);
        $this->assertSame($organization->id, $registration->organization_id);
    }

    private function botRemovedMyChatMemberPayload(): array
    {
        return [
            'update_id' => 1003,
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
                    'status' => 'member',
                    'user' => [
                        'id' => 777000,
                        'is_bot' => true,
                        'username' => 'wanda_test_bot',
                        'first_name' => 'Tribes',
                    ],
                ],
                'new_chat_member' => [
                    'status' => 'kicked',
                    'user' => [
                        'id' => 777000,
                        'is_bot' => true,
                        'username' => 'wanda_test_bot',
                        'first_name' => 'Tribes',
                    ],
                ],
            ],
        ];
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
                        'first_name' => 'Tribes',
                    ],
                ],
                'new_chat_member' => [
                    'status' => 'member',
                    'user' => [
                        'id' => 777000,
                        'is_bot' => true,
                        'username' => 'wanda_test_bot',
                        'first_name' => 'Tribes',
                    ],
                ],
            ],
        ];
    }

    private function forumTopicCreatedPayload(): array
    {
        return [
            'update_id' => 1004,
            'message' => [
                'message_id' => 3,
                'message_thread_id' => 336,
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
                'forum_topic_created' => [
                    'name' => 'Backend Focus',
                    'icon_color' => 7322096,
                ],
            ],
        ];
    }

    private function groupMessagePayload(string $text): array
    {
        return [
            'update_id' => 1002,
            'message' => [
                'message_id' => 2,
                'date' => now()->timestamp,
                'text' => $text,
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
