<?php

namespace Tests\Feature\Services;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Chat\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConversationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(ConversationService::class);
    }

    /** @test */
    public function it_creates_web_conversation_with_owner_participant(): void
    {
        $user = User::factory()->create();

        $conv = $this->service->createWebConversation($user, 'Test Chat');

        $this->assertInstanceOf(Conversation::class, $conv);
        $this->assertEquals(ChannelType::Web, $conv->channel_type);
        $this->assertEquals('Test Chat', $conv->title);
        $this->assertTrue($conv->isOwner($user));
    }

    /** @test */
    public function it_creates_web_conversation_without_title(): void
    {
        $user = User::factory()->create();

        $conv = $this->service->createWebConversation($user);

        $this->assertNull($conv->title);
        $this->assertTrue($conv->isOwner($user));
    }

    /** @test */
    public function it_gets_conversations_for_user_returns_only_web_type(): void
    {
        $user = User::factory()->create();
        $this->service->createWebConversation($user, 'Web Chat');

        // Telegram conversation — не должен попасть в список
        $telegramConv = Conversation::create([
            'channel_type' => ChannelType::TelegramPrivate,
            'external_id'  => '12345',
        ]);
        $telegramConv->addParticipant($user, 'member');

        $conversations = $this->service->getConversationsForUser($user);

        $this->assertCount(1, $conversations);
        $this->assertTrue($conversations->first()->isWeb());
    }

    /** @test */
    public function it_gets_conversations_for_user_returns_only_own(): void
    {
        $user      = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->service->createWebConversation($user, 'My Chat');
        $this->service->createWebConversation($otherUser, 'Other Chat');

        $conversations = $this->service->getConversationsForUser($user);

        $this->assertCount(1, $conversations);
        $this->assertEquals('My Chat', $conversations->first()->title);
    }

    /** @test */
    public function it_counts_conversations_for_user(): void
    {
        $user = User::factory()->create();
        $this->service->createWebConversation($user);
        $this->service->createWebConversation($user);

        $count = $this->service->countConversationsForUser($user);

        $this->assertEquals(2, $count);
    }

    /** @test */
    public function it_finds_or_creates_telegram_conversation_creates_new(): void
    {
        $telegramUser = TelegramUser::create(['telegram_user_id' => 55555]);

        $conv = $this->service->findOrCreateTelegramConversation(
            $telegramUser,
            -100987654,
            ChannelType::TelegramGroup
        );

        $this->assertEquals(ChannelType::TelegramGroup, $conv->channel_type);
        $this->assertEquals('-100987654', $conv->external_id);
        $this->assertTrue($conv->hasParticipant($telegramUser));
    }

    /** @test */
    public function it_finds_or_creates_telegram_conversation_returns_existing(): void
    {
        $telegramUser = TelegramUser::create(['telegram_user_id' => 55555]);
        $existing     = Conversation::create([
            'channel_type' => ChannelType::TelegramGroup,
            'external_id'  => '-100987654',
        ]);

        $conv = $this->service->findOrCreateTelegramConversation(
            $telegramUser,
            -100987654,
            ChannelType::TelegramGroup
        );

        $this->assertEquals($existing->id, $conv->id);
    }

    /** @test */
    public function it_adds_new_participant_when_finding_existing_telegram_conversation(): void
    {
        $telegramUser  = TelegramUser::create(['telegram_user_id' => 11111]);
        $telegramUser2 = TelegramUser::create(['telegram_user_id' => 22222]);

        $this->service->findOrCreateTelegramConversation($telegramUser, -100111, ChannelType::TelegramGroup);
        $this->service->findOrCreateTelegramConversation($telegramUser2, -100111, ChannelType::TelegramGroup);

        $conv = Conversation::findByExternalId(ChannelType::TelegramGroup, '-100111');
        $this->assertEquals(2, $conv->participants()->count());
    }

    /** @test */
    public function it_skips_duplicate_participant_when_same_user_rejoins(): void
    {
        $telegramUser = TelegramUser::create(['telegram_user_id' => 33333]);

        $this->service->findOrCreateTelegramConversation($telegramUser, -100222, ChannelType::TelegramGroup);
        $this->service->findOrCreateTelegramConversation($telegramUser, -100222, ChannelType::TelegramGroup);

        $conv = Conversation::findByExternalId(ChannelType::TelegramGroup, '-100222');
        $this->assertEquals(1, $conv->participants()->count());
    }

    /** @test */
    public function it_deletes_conversation_and_cascades_messages_and_participants(): void
    {
        $user = User::factory()->create();
        $conv = $this->service->createWebConversation($user, 'Delete me');
        Message::create([
            'conversation_id' => $conv->id,
            'role'            => 'user',
            'content'         => 'Hello',
        ]);

        $this->service->delete($conv);

        $this->assertDatabaseMissing('conversations', ['id' => $conv->id]);
        $this->assertDatabaseMissing('conversation_participants', ['conversation_id' => $conv->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conv->id]);
    }

    /** @test */
    public function it_updates_conversation_title(): void
    {
        $user = User::factory()->create();
        $conv = $this->service->createWebConversation($user, 'Old Title');

        $updated = $this->service->update($conv, 'New Title');

        $this->assertEquals('New Title', $updated->title);
        $this->assertDatabaseHas('conversations', ['id' => $conv->id, 'title' => 'New Title']);
    }

    /** @test */
    public function it_finds_conversation_by_id_or_fails(): void
    {
        $user = User::factory()->create();
        $conv = $this->service->createWebConversation($user);

        $found = $this->service->findOrFail($conv->id);

        $this->assertEquals($conv->id, $found->id);
    }

    /** @test */
    public function it_throws_when_conversation_not_found(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->service->findOrFail(99999);
    }
}
