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
    public function manager_can_list_and_issue_attach_code_for_discovered_chat(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager, 'manager');

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(123456789, 777),
            'telegram_chat_id' => 123456789,
            'message_thread_id' => 777,
        ]);

        $registration = TelegramChatRegistration::create([
            'channel_conversation_id' => $conversation->id,
            'telegram_chat_id' => 123456789,
            'message_thread_id' => 777,
            'chat_type' => 'supergroup',
            'chat_title' => 'Engineering',
        ]);

        $this->actingAs($manager)
            ->getJson('/api/v1/telegram/chats')
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => $registration->id,
                'telegram_chat_id' => 123456789,
                'chat_title' => 'Engineering',
            ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/telegram/chats/{$registration->id}/attach-code", [
                'organization_id' => $organization->id,
                'team_id' => $team->id,
            ])->assertStatus(200)
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.team_id', $team->id);

        $registration->refresh();

        $this->assertMatchesRegularExpression('/^[A-Z]{3}-[A-Z]{3}$/', (string) $registration->attach_code);
        $this->assertNotNull($registration->attach_code_expires_at);
        $this->assertSame($manager->id, $registration->attach_requested_by_user_id);
    }

    #[Test]
    public function employee_cannot_issue_attach_code(): void
    {
        $employee = User::factory()->create();
        [$organization] = $this->createTenantContextFor($employee, 'employee');

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(123456789, null),
            'telegram_chat_id' => 123456789,
        ]);

        $registration = TelegramChatRegistration::create([
            'channel_conversation_id' => $conversation->id,
            'telegram_chat_id' => 123456789,
            'chat_type' => 'group',
            'chat_title' => 'Ops',
        ]);

        $this->actingAs($employee)
            ->postJson("/api/v1/telegram/chats/{$registration->id}/attach-code", [
                'organization_id' => $organization->id,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id']);

        $this->assertNull($registration->fresh()->attach_code);
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

    #[Test]
    public function private_chat_cannot_receive_attach_code(): void
    {
        $manager = User::factory()->create();
        [$organization] = $this->createTenantContextFor($manager, 'manager');

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(333444, null),
            'telegram_chat_id' => 333444,
            'user_id' => $manager->id,
        ]);

        $registration = TelegramChatRegistration::create([
            'channel_conversation_id' => $conversation->id,
            'telegram_chat_id' => 333444,
            'chat_type' => 'private',
            'chat_title' => 'Direct chat',
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/telegram/chats/{$registration->id}/attach-code", [
                'organization_id' => $organization->id,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['telegram_chat']);
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
