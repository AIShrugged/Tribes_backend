<?php

namespace Tests\Feature;

use App\Enums\ConversationChannelType;
use App\Models\ChannelConversation;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TelegramChatRegistration;
use App\Models\User;
use App\Services\TelegramChatRegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelegramChatRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_binds_private_telegram_conversation_to_user_without_tenant_scope(): void
    {
        $user = User::factory()->create();

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(777001, null),
            'telegram_chat_id' => 777001,
        ]);

        $service = $this->app->make(TelegramChatRegistrationService::class);
        $registration = $service->bindPrivateConversation($conversation, $user);

        $conversation->refresh();
        $registration->refresh();

        $this->assertSame($user->id, $conversation->user_id);
        $this->assertNull($conversation->organization_id);
        $this->assertNull($conversation->team_id);
        $this->assertSame('private', $registration->chat_type);
        $this->assertNull($registration->organization_id);
        $this->assertNull($registration->team_id);
        $this->assertNull($registration->attach_code);
        $this->assertNotNull($registration->bound_at);
        $this->assertSame($user->id, $registration->bound_by_user_id);
    }

    #[Test]
    public function create_workspace_chat_creates_pre_registration_when_bot_not_in_chat(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager, 'manager');

        $service = $this->app->make(TelegramChatRegistrationService::class);
        $registration = $service->createWorkspaceChat('Dev Chat', 555123, null, $organization->id, $team->id, $manager);

        $this->assertNull($registration->channel_conversation_id);
        $this->assertNull($registration->bound_at);
        $this->assertSame($organization->id, $registration->organization_id);
        $this->assertSame('Dev Chat', $registration->chat_title);
    }

    #[Test]
    public function create_workspace_chat_binds_immediately_if_bot_already_discovered(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager, 'manager');

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(555124, null),
            'telegram_chat_id' => 555124,
        ]);

        TelegramChatRegistration::create([
            'channel_conversation_id' => $conversation->id,
            'telegram_chat_id' => 555124,
            'chat_type' => 'supergroup',
            'chat_title' => 'Auto title from TG',
        ]);

        $service = $this->app->make(TelegramChatRegistrationService::class);
        $registration = $service->createWorkspaceChat('My Chat Name', 555124, null, $organization->id, $team->id, $manager);

        $this->assertNotNull($registration->bound_at);
        $this->assertSame($conversation->id, $registration->channel_conversation_id);
        $this->assertSame($organization->id, $registration->organization_id);
        $this->assertSame('My Chat Name', $registration->chat_title);
    }

    #[Test]
    public function create_workspace_chat_rejects_duplicate_registration(): void
    {
        $manager = User::factory()->create();
        [$organization] = $this->createTenantContextFor($manager, 'manager');

        $service = $this->app->make(TelegramChatRegistrationService::class);
        $service->createWorkspaceChat('First', 555125, null, $organization->id, null, $manager);

        $this->expectException(ValidationException::class);
        $service->createWorkspaceChat('Duplicate', 555125, null, $organization->id, null, $manager);
    }

    #[Test]
    public function create_workspace_chat_can_pre_register_a_topic(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager, 'manager');

        $service = $this->app->make(TelegramChatRegistrationService::class);
        $registration = $service->createWorkspaceChat('Dev Topic', 555129, 336, $organization->id, $team->id, $manager);

        $this->assertNull($registration->channel_conversation_id);
        $this->assertNull($registration->bound_at);
        $this->assertSame(336, $registration->message_thread_id);
        $this->assertSame($organization->id, $registration->organization_id);
        $this->assertSame($team->id, $registration->team_id);
    }

    #[Test]
    public function create_workspace_chat_binds_discovered_topic_immediately(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager, 'manager');

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(555130, 441),
            'telegram_chat_id' => 555130,
            'message_thread_id' => 441,
        ]);

        TelegramChatRegistration::create([
            'channel_conversation_id' => $conversation->id,
            'telegram_chat_id' => 555130,
            'message_thread_id' => 441,
            'chat_type' => 'supergroup',
            'chat_title' => 'Auto title from TG',
        ]);

        $service = $this->app->make(TelegramChatRegistrationService::class);
        $registration = $service->createWorkspaceChat('My Topic', 555130, 441, $organization->id, $team->id, $manager);

        $this->assertNotNull($registration->bound_at);
        $this->assertSame($conversation->id, $registration->channel_conversation_id);
        $this->assertSame(441, $registration->message_thread_id);
        $this->assertSame($organization->id, $registration->organization_id);
        $this->assertSame('My Topic', $registration->chat_title);
    }

    #[Test]
    public function database_rejects_duplicate_root_chat_registrations(): void
    {
        $manager = User::factory()->create();
        [$organization] = $this->createTenantContextFor($manager, 'manager');

        TelegramChatRegistration::create([
            'telegram_chat_id' => 555128,
            'chat_type' => 'group',
            'organization_id' => $organization->id,
        ]);

        $this->expectException(QueryException::class);

        TelegramChatRegistration::create([
            'telegram_chat_id' => 555128,
            'chat_type' => 'group',
            'organization_id' => $organization->id,
        ]);
    }

    #[Test]
    public function discover_group_conversation_binds_pre_registered_chat(): void
    {
        $manager = User::factory()->create();
        [$organization] = $this->createTenantContextFor($manager, 'manager');

        TelegramChatRegistration::create([
            'telegram_chat_id' => 555126,
            'chat_title' => 'Pre-registered',
            'chat_type' => 'group',
            'organization_id' => $organization->id,
        ]);

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(555126, null),
            'telegram_chat_id' => 555126,
        ]);

        $service = $this->app->make(TelegramChatRegistrationService::class);
        $registration = $service->discoverGroupConversation($conversation, 'supergroup', 'TG Title');

        $this->assertNotNull($registration->bound_at);
        $this->assertSame($conversation->id, $registration->channel_conversation_id);
        $this->assertSame('Pre-registered', $registration->chat_title);
    }

    #[Test]
    public function discover_group_conversation_saves_topic_title_on_conversation(): void
    {
        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(555131, 777),
            'telegram_chat_id' => 555131,
            'message_thread_id' => 777,
        ]);

        $service = $this->app->make(TelegramChatRegistrationService::class);
        $registration = $service->discoverGroupConversation($conversation, 'supergroup', 'Engineering Room', 'Backend Focus');

        $this->assertSame('Backend Focus', $conversation->refresh()->title);
        $this->assertSame($conversation->id, $registration->channel_conversation_id);
        $this->assertSame(777, $registration->message_thread_id);
    }

    #[Test]
    public function discover_group_conversation_does_not_bind_unregistered_chat(): void
    {
        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(555127, null),
            'telegram_chat_id' => 555127,
        ]);

        $service = $this->app->make(TelegramChatRegistrationService::class);
        $registration = $service->discoverGroupConversation($conversation, 'group', 'Unknown Chat');

        $this->assertNull($registration->bound_at);
        $this->assertNull($registration->organization_id);
        $this->assertSame('Unknown Chat', $registration->chat_title);
    }

    private function createTenantContextFor(User $user, string $role): array
    {
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $organization = Organization::create([
            'name' => 'Acme Telegram Service',
            'slug' => 'acme-telegram-service',
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Delivery',
            'slug' => 'delivery',
        ]);

        $organization->users()->attach($user->id, ['role' => $role]);
        $team->users()->attach($user->id);

        return [$organization, $team];
    }
}
