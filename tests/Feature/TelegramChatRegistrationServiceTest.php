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
    public function it_attaches_telegram_conversation_by_one_time_code(): void
    {
        $manager = User::factory()->create();
        [$organization, $team] = $this->createTenantContextFor($manager, 'manager');

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(555123, null),
            'telegram_chat_id' => 555123,
        ]);

        $registration = TelegramChatRegistration::create([
            'channel_conversation_id' => $conversation->id,
            'telegram_chat_id' => 555123,
            'chat_type' => 'group',
            'chat_title' => 'Product',
            'attach_code' => 'COD-EEE',
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'attach_code_issued_at' => now(),
            'attach_code_expires_at' => now()->addMinutes(30),
        ]);

        $service = $this->app->make(TelegramChatRegistrationService::class);
        $service->attachConversationByCode($conversation, 'cod-eee', $manager);

        $conversation->refresh();
        $registration->refresh();

        $this->assertSame($manager->id, $conversation->user_id);
        $this->assertSame($organization->id, $conversation->organization_id);
        $this->assertSame($team->id, $conversation->team_id);
        $this->assertNotNull($registration->attach_code_used_at);
        $this->assertNotNull($registration->bound_at);
        $this->assertSame($manager->id, $registration->bound_by_user_id);
    }

    #[Test]
    public function it_rejects_expired_attach_code(): void
    {
        $manager = User::factory()->create();
        [$organization] = $this->createTenantContextFor($manager, 'manager');

        $conversation = ChannelConversation::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(555124, null),
            'telegram_chat_id' => 555124,
        ]);

        TelegramChatRegistration::create([
            'channel_conversation_id' => $conversation->id,
            'telegram_chat_id' => 555124,
            'attach_code' => 'EXP-IRD',
            'organization_id' => $organization->id,
            'attach_code_issued_at' => now()->subHour(),
            'attach_code_expires_at' => now()->subMinute(),
        ]);

        $service = $this->app->make(TelegramChatRegistrationService::class);

        $this->expectException(ValidationException::class);
        $service->attachConversationByCode($conversation, 'EXP-IRD', $manager);
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
