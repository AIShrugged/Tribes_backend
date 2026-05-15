<?php

namespace Tests\Feature;

use App\Enums\ConversationChannelType;
use App\Models\ChannelConversation;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TelegramChatRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelegramChatRegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function manager_can_list_chats(): void
    {
        $manager = User::factory()->create();
        [$organization] = $this->createTenantContextFor($manager, 'manager');

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(123456789, null),
            'telegram_chat_id' => 123456789,
        ]);

        TelegramChatRegistration::create([
            'channel_conversation_id' => $conversation->id,
            'telegram_chat_id' => 123456789,
            'chat_type' => 'supergroup',
            'chat_title' => 'Engineering',
            'organization_id' => $organization->id,
            'bound_at' => now(),
        ]);

        $this->actingAs($manager)
            ->getJson('/api/v1/telegram/chats')
            ->assertStatus(200)
            ->assertJsonFragment([
                'telegram_chat_id' => 123456789,
                'chat_title' => 'Engineering',
                'is_bound' => true,
            ]);
    }

    #[Test]
    public function manager_can_create_workspace_chat(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager, 'manager');

        $this->actingAs($manager)
            ->postJson('/api/v1/telegram/chats', [
                'name' => 'Product Team',
                'telegram_chat_id' => 987654321,
                'organization_id' => $organization->id,
                'team_id' => $team->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.telegram_chat_id', 987654321)
            ->assertJsonPath('data.chat_title', 'Product Team')
            ->assertJsonPath('data.is_bound', false);

        $this->assertDatabaseHas('telegram_chat_registrations', [
            'telegram_chat_id' => 987654321,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
        ]);
    }

    #[Test]
    public function employee_cannot_create_workspace_chat(): void
    {
        $employee = User::factory()->create();
        [$organization] = $this->createTenantContextFor($employee, 'employee');

        $this->actingAs($employee)
            ->postJson('/api/v1/telegram/chats', [
                'name' => 'Ops Chat',
                'telegram_chat_id' => 111222333,
                'organization_id' => $organization->id,
            ])->assertStatus(422);
    }

    #[Test]
    public function manager_cannot_register_duplicate_chat_id(): void
    {
        $manager = User::factory()->create();
        [$organization] = $this->createTenantContextFor($manager, 'manager');

        $this->actingAs($manager)
            ->postJson('/api/v1/telegram/chats', [
                'name' => 'First',
                'telegram_chat_id' => 555000,
                'organization_id' => $organization->id,
            ])->assertStatus(200);

        $this->actingAs($manager)
            ->postJson('/api/v1/telegram/chats', [
                'name' => 'Duplicate',
                'telegram_chat_id' => 555000,
                'organization_id' => $organization->id,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['telegram_chat_id']);
    }

    #[Test]
    public function manager_can_delete_workspace_chat(): void
    {
        $manager = User::factory()->create();
        [$organization] = $this->createTenantContextFor($manager, 'manager');

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(777888999, null),
            'telegram_chat_id' => 777888999,
        ]);

        $registration = TelegramChatRegistration::create([
            'channel_conversation_id' => $conversation->id,
            'telegram_chat_id' => 777888999,
            'chat_type' => 'supergroup',
            'chat_title' => 'To Delete',
            'organization_id' => $organization->id,
            'bound_at' => now(),
        ]);

        $this->actingAs($manager)
            ->deleteJson("/api/v1/telegram/chats/{$registration->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('telegram_chat_registrations', ['id' => $registration->id]);
        $this->assertDatabaseMissing('channel_conversations', ['id' => $conversation->id]);
    }

    #[Test]
    public function user_sees_only_own_private_chats(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownConversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(200001, null),
            'telegram_chat_id' => 200001,
            'user_id' => $user->id,
        ]);

        $foreignConversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(200002, null),
            'telegram_chat_id' => 200002,
            'user_id' => $otherUser->id,
        ]);

        TelegramChatRegistration::create([
            'channel_conversation_id' => $ownConversation->id,
            'telegram_chat_id' => 200001,
            'chat_type' => 'private',
            'chat_title' => 'Own private chat',
        ]);

        TelegramChatRegistration::create([
            'channel_conversation_id' => $foreignConversation->id,
            'telegram_chat_id' => 200002,
            'chat_type' => 'private',
            'chat_title' => 'Foreign private chat',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/telegram/chats')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.telegram_chat_id', 200001)
            ->assertJsonPath('data.0.chat_type', 'private')
            ->assertJsonPath('data.0.user_id', $user->id);
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
            'name' => 'Acme Telegram Admin',
            'slug' => 'acme-telegram-admin',
        ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Core',
            'slug' => 'core',
        ]);

        $organization->users()->attach($user->id, ['role' => $role]);
        $team->users()->attach($user->id);

        return [$organization, $team];
    }
}
