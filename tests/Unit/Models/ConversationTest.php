<?php

namespace Tests\Unit\Models;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    // --- addParticipant ---

    /** @test */
    public function it_can_add_user_as_owner_participant(): void
    {
        $conv = Conversation::create(['channel_type' => ChannelType::Web]);
        $user = User::factory()->create();

        $participant = $conv->addParticipant($user, 'owner');

        $this->assertInstanceOf(ConversationParticipant::class, $participant);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id'      => $conv->id,
            'participantable_type' => User::class,
            'participantable_id'   => $user->id,
            'role'                 => 'owner',
        ]);
    }

    /** @test */
    public function it_can_add_telegram_user_as_member_participant(): void
    {
        $conv         = Conversation::create(['channel_type' => ChannelType::TelegramGroup]);
        $telegramUser = TelegramUser::create(['telegram_user_id' => 12345, 'telegram_username' => 'testuser']);

        $participant = $conv->addParticipant($telegramUser, 'member');

        $this->assertInstanceOf(ConversationParticipant::class, $participant);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id'      => $conv->id,
            'participantable_type' => TelegramUser::class,
            'participantable_id'   => $telegramUser->getKey(),
            'role'                 => 'member',
        ]);
    }

    // --- hasParticipant ---

    /** @test */
    public function it_returns_true_when_user_is_participant(): void
    {
        $conv = Conversation::create(['channel_type' => ChannelType::Web]);
        $user = User::factory()->create();
        $conv->addParticipant($user, 'owner');

        $this->assertTrue($conv->hasParticipant($user));
    }

    /** @test */
    public function it_returns_false_when_user_is_not_participant(): void
    {
        $conv      = Conversation::create(['channel_type' => ChannelType::Web]);
        $otherUser = User::factory()->create();

        $this->assertFalse($conv->hasParticipant($otherUser));
    }

    /** @test */
    public function it_returns_true_when_telegram_user_is_participant(): void
    {
        $conv         = Conversation::create(['channel_type' => ChannelType::TelegramPrivate]);
        $telegramUser = TelegramUser::create(['telegram_user_id' => 99999]);
        $conv->addParticipant($telegramUser, 'member');

        $this->assertTrue($conv->hasParticipant($telegramUser));
    }

    // --- isOwner ---

    /** @test */
    public function it_returns_true_for_owner_participant(): void
    {
        $conv = Conversation::create(['channel_type' => ChannelType::Web]);
        $user = User::factory()->create();
        $conv->addParticipant($user, 'owner');

        $this->assertTrue($conv->isOwner($user));
    }

    /** @test */
    public function it_returns_false_for_member_participant(): void
    {
        $conv   = Conversation::create(['channel_type' => ChannelType::Web]);
        $owner  = User::factory()->create();
        $member = User::factory()->create();
        $conv->addParticipant($owner, 'owner');
        $conv->addParticipant($member, 'member');

        $this->assertFalse($conv->isOwner($member));
    }

    /** @test */
    public function it_returns_false_for_non_participant(): void
    {
        $conv = Conversation::create(['channel_type' => ChannelType::Web]);
        $user = User::factory()->create();

        $this->assertFalse($conv->isOwner($user));
    }

    // --- channel type helpers ---

    /** @test */
    public function it_identifies_telegram_group_type(): void
    {
        $conv = Conversation::create(['channel_type' => ChannelType::TelegramGroup]);

        $this->assertTrue($conv->isGroup());
        $this->assertTrue($conv->isTelegram());
        $this->assertFalse($conv->isWeb());
    }

    /** @test */
    public function it_identifies_telegram_private_type(): void
    {
        $conv = Conversation::create(['channel_type' => ChannelType::TelegramPrivate]);

        $this->assertFalse($conv->isGroup());
        $this->assertTrue($conv->isTelegram());
        $this->assertFalse($conv->isWeb());
    }

    /** @test */
    public function it_identifies_web_type(): void
    {
        $conv = Conversation::create(['channel_type' => ChannelType::Web]);

        $this->assertFalse($conv->isGroup());
        $this->assertFalse($conv->isTelegram());
        $this->assertTrue($conv->isWeb());
    }

    // --- findByExternalId ---

    /** @test */
    public function it_finds_conversation_by_external_id(): void
    {
        $conv = Conversation::create([
            'channel_type' => ChannelType::TelegramGroup,
            'external_id'  => '-100123456',
        ]);

        $found = Conversation::findByExternalId(ChannelType::TelegramGroup, '-100123456');

        $this->assertNotNull($found);
        $this->assertEquals($conv->id, $found->id);
    }

    /** @test */
    public function it_returns_null_when_external_id_not_found(): void
    {
        $found = Conversation::findByExternalId(ChannelType::TelegramGroup, '-999999');

        $this->assertNull($found);
    }

    /** @test */
    public function it_does_not_confuse_same_external_id_across_channel_types(): void
    {
        Conversation::create([
            'channel_type' => ChannelType::TelegramPrivate,
            'external_id'  => '12345',
        ]);

        $found = Conversation::findByExternalId(ChannelType::TelegramGroup, '12345');

        $this->assertNull($found);
    }
}
