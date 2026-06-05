<?php

namespace Tests\Feature;

use App\Models\Methodology;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\TaskDataUpload;
use App\Models\Team;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use App\Models\TelegramUser;
use App\Models\User;
use App\Jobs\ProcessTaskDataUploadJob;
use App\Jobs\SendTaskDataUploadReportJob;
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
    public function private_messages_are_not_blocked_by_telegram_allowed_users_config(): void
    {
        config()->set('telegram.allowed_users', '111111');

        $user = User::factory()->create();
        TelegramUser::query()->create([
            'telegram_user_id' => 900001,
            'telegram_username' => 'manager_user',
            'user_id' => $user->id,
        ]);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->privateMessagePayload('/forget')));
        $telegramApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 900001
                && str_contains($params['text'], 'История сброшена'));

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('channel_messages', [
            'role' => 'user',
            'content' => '/forget',
        ]);
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

    #[Test]
    public function bound_group_document_is_queued_as_task_data_upload(): void
    {
        Queue::fake();

        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager);

        TelegramUser::query()->create([
            'telegram_user_id' => 900001,
            'telegram_username' => 'manager_user',
            'user_id' => $manager->id,
        ]);

        $this->actingAs($manager)->postJson('/api/v1/telegram/chats', [
            'name' => 'Engineering Room',
            'telegram_chat_id' => 555123,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
        ])->assertStatus(200);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->groupDocumentPayload()));
        $telegramApi->shouldReceive('downloadFile')
            ->once()
            ->andReturnUsing(function ($document, string $filename): string {
                file_put_contents($filename, "Task: prepare launch checklist\nOwner: Alice");

                return $filename;
            });
        $telegramApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 555123
                && str_contains($params['text'], 'Файл принят'));

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('task_data_uploads', [
            'user_id' => $manager->id,
            'team_id' => $organization->refresh()->defaultTeam->id,
            'organization_id' => $organization->id,
            'original_filename' => 'tasks.txt',
            'source_telegram_chat_id' => 555123,
            'source_telegram_thread_id' => null,
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessTaskDataUploadJob::class);
    }

    #[Test]
    public function private_document_with_multiple_organizations_asks_for_organization_not_team(): void
    {
        $manager = User::factory()->create();
        $first = $this->createTenantContextFor($manager, 'acme-private-a')[0];
        $second = $this->createTenantContextFor($manager, 'acme-private-b')[0];

        TelegramUser::query()->create([
            'telegram_user_id' => 900001,
            'telegram_username' => 'manager_user',
            'user_id' => $manager->id,
        ]);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->privateDocumentPayload()));
        $telegramApi->shouldNotReceive('downloadFile');
        $telegramApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 900001
                && str_contains($params['text'], 'Укажите организацию')
                && str_contains($params['text'], $first->name)
                && str_contains($params['text'], $second->name)
                && ! str_contains($params['text'], 'Укажите команду'));

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseCount('task_data_uploads', 0);
    }

    #[Test]
    public function private_document_uses_default_team_for_caption_organization(): void
    {
        Queue::fake();

        $manager = User::factory()->create();
        $organization = $this->createTenantContextFor($manager, 'acme-private-upload')[0];
        $other = $this->createTenantContextFor($manager, 'other-private-upload')[0];

        TelegramUser::query()->create([
            'telegram_user_id' => 900001,
            'telegram_username' => 'manager_user',
            'user_id' => $manager->id,
        ]);

        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->once()
            ->andReturn(new Update($this->privateDocumentPayload('Организация: '.$organization->slug)));
        $telegramApi->shouldReceive('downloadFile')
            ->once()
            ->andReturnUsing(function ($document, string $filename): string {
                file_put_contents($filename, "Task: prepare launch checklist\nOwner: Alice");

                return $filename;
            });
        $telegramApi->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn (array $params) => $params['chat_id'] === 900001
                && str_contains($params['text'], 'Файл принят'));

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('task_data_uploads', [
            'user_id' => $manager->id,
            'team_id' => $organization->refresh()->defaultTeam->id,
            'organization_id' => $organization->id,
            'original_filename' => 'tasks.txt',
            'source_telegram_chat_id' => 900001,
            'source_telegram_thread_id' => null,
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('task_data_uploads', [
            'organization_id' => $other->id,
        ]);

        Queue::assertPushed(ProcessTaskDataUploadJob::class);
    }

    #[Test]
    public function private_document_waits_for_organization_reply_and_then_uploads_file(): void
    {
        Queue::fake();

        $manager = User::factory()->create();
        $organization = $this->createTenantContextFor($manager, 'acme-pending-upload')[0];
        $other = $this->createTenantContextFor($manager, 'other-pending-upload')[0];

        TelegramUser::query()->create([
            'telegram_user_id' => 900001,
            'telegram_username' => 'manager_user',
            'user_id' => $manager->id,
        ]);

        $sent = [];
        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('getWebhookUpdate')
            ->twice()
            ->andReturnUsing(
                fn () => new Update($this->privateDocumentPayload()),
                fn () => new Update($this->privateMessagePayload($organization->slug)),
            );
        $telegramApi->shouldReceive('downloadFile')
            ->once()
            ->withArgs(fn ($file, string $filename) => $file === 'telegram-file-1'
                && str_ends_with($filename, '.txt'))
            ->andReturnUsing(function ($file, string $filename): string {
                file_put_contents($filename, "Task: prepare launch checklist\nOwner: Alice");

                return $filename;
            });
        $telegramApi->shouldReceive('sendMessage')
            ->twice()
            ->andReturnUsing(function (array $params) use (&$sent): array {
                $sent[] = $params;

                return ['ok' => true];
            });

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseCount('task_data_uploads', 0);
        $this->assertStringContainsString('Укажите организацию', $sent[0]['text']);

        $this->postJson('/api/v1/telegram/webhook')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertStringContainsString('Файл принят', $sent[1]['text']);
        $this->assertDatabaseHas('task_data_uploads', [
            'user_id' => $manager->id,
            'team_id' => $organization->refresh()->defaultTeam->id,
            'organization_id' => $organization->id,
            'original_filename' => 'tasks.txt',
            'source_telegram_chat_id' => 900001,
            'source_telegram_thread_id' => null,
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('task_data_uploads', [
            'organization_id' => $other->id,
        ]);
        $this->assertDatabaseMissing('channel_messages', [
            'role' => 'user',
            'content' => $organization->slug,
        ]);

        Queue::assertPushed(ProcessTaskDataUploadJob::class);
    }

    #[Test]
    public function task_data_upload_report_goes_to_source_chat_and_summary_chats(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager);

        TelegramUser::query()->create([
            'telegram_user_id' => 900001,
            'telegram_username' => 'manager_user',
            'user_id' => $manager->id,
        ]);

        $summaryRegistration = TelegramChatRegistration::query()->create([
            'telegram_chat_id' => 777888,
            'message_thread_id' => 42,
            'chat_type' => 'supergroup',
            'chat_title' => 'Protocols',
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'bound_at' => now(),
        ]);

        TeamNotificationSetting::query()->create([
            'team_id' => $team->id,
            'event_type' => 'meeting_summary',
            'channel_type' => 'telegram',
            'notifiable_type' => TelegramChatRegistration::class,
            'notifiable_id' => $summaryRegistration->id,
            'enabled' => true,
        ]);

        $upload = TaskDataUpload::query()->create([
            'user_id' => $manager->id,
            'team_id' => $team->id,
            'organization_id' => $organization->id,
            'original_filename' => 'tasks.txt',
            'status' => 'done',
            'source_telegram_chat_id' => 555123,
            'source_telegram_thread_id' => null,
        ]);

        $issue = Issue::query()->create([
            'name' => 'Prepare launch checklist',
            'status' => 'open',
            'team_id' => $team->id,
            'organization_id' => $organization->id,
            'user_id' => $manager->id,
            'sourceable_type' => TaskDataUpload::class,
            'sourceable_id' => $upload->id,
        ]);

        $sent = [];
        $telegramApi = Mockery::mock('overload:Telegram\Bot\Api');
        $telegramApi->shouldReceive('sendMessage')
            ->times(3)
            ->andReturnUsing(function (array $params) use (&$sent): array {
                $sent[] = $params;

                return ['ok' => true];
            });

        (new SendTaskDataUploadReportJob($upload, [$issue->id], []))->handle();

        $this->assertSame([900001, 555123, 777888], array_column($sent, 'chat_id'));
        $this->assertSame(42, $sent[2]['message_thread_id']);
        $this->assertStringContainsString('Task data upload report', $sent[0]['text']);
        $this->assertStringContainsString('Prepare launch checklist', $sent[0]['text']);
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

    private function privateMessagePayload(string $text): array
    {
        return [
            'update_id' => 1006,
            'message' => [
                'message_id' => 6,
                'date' => now()->timestamp,
                'text' => $text,
                'chat' => [
                    'id' => 900001,
                    'type' => 'private',
                    'first_name' => 'Manager',
                    'username' => 'manager_user',
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

    private function groupDocumentPayload(): array
    {
        return [
            'update_id' => 1005,
            'message' => [
                'message_id' => 5,
                'date' => now()->timestamp,
                'caption' => 'Команда: Platform',
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
                'document' => [
                    'file_id' => 'telegram-file-1',
                    'file_unique_id' => 'unique-file-1',
                    'file_name' => 'tasks.txt',
                    'mime_type' => 'text/plain',
                    'file_size' => 128,
                ],
            ],
        ];
    }

    private function privateDocumentPayload(?string $caption = null): array
    {
        $payload = [
            'update_id' => 1007,
            'message' => [
                'message_id' => 7,
                'date' => now()->timestamp,
                'chat' => [
                    'id' => 900001,
                    'type' => 'private',
                    'first_name' => 'Manager',
                    'username' => 'manager_user',
                ],
                'from' => [
                    'id' => 900001,
                    'is_bot' => false,
                    'username' => 'manager_user',
                    'first_name' => 'Manager',
                ],
                'document' => [
                    'file_id' => 'telegram-file-1',
                    'file_unique_id' => 'unique-file-1',
                    'file_name' => 'tasks.txt',
                    'mime_type' => 'text/plain',
                    'file_size' => 128,
                ],
            ],
        ];

        if ($caption !== null) {
            $payload['message']['caption'] = $caption;
        }

        return $payload;
    }

    private function createTenantContextFor(User $user, string $slug = 'acme-telegram-webhook'): array
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Acme Telegram Webhook '.$slug,
            'slug' => $slug,
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Platform',
            'slug' => 'platform-webhook-'.$slug,
        ]);

        $organization->users()->attach($user->id, ['role' => 'manager']);
        $team->users()->attach($user->id);

        return [$organization, $team];
    }
}
