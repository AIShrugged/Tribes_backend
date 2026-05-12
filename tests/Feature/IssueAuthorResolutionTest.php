<?php

namespace Tests\Feature;

use App\Enums\ConversationChannelType;
use App\Enums\MeetingTaskStatus;
use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\ChannelConversation;
use App\Models\ChannelIdentity;
use App\Models\ChannelMessage;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Profile;
use App\Models\Source;
use App\Models\Team;
use App\Models\TranscriptEntry;
use App\Models\User;
use App\Services\IssueExtractionService;
use App\Services\IssueMergeService;
use App\Services\OpenRouterClient;
use App\Services\Task\TelegramTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueAuthorResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Team $team;
    private User $owner;
    private User $speaker;
    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'AR Org', 'slug' => 'ar-org']);

        $this->owner = User::factory()->create(['name' => 'Olga Owner']);
        $this->speaker = User::factory()->create(['name' => 'Sergey Speaker']);
        $this->org->users()->attach($this->owner, ['role' => 'employee']);
        $this->org->users()->attach($this->speaker, ['role' => 'employee']);

        $methodology = Methodology::create([
            'name'            => 'Test',
            'text'            => 'M',
            'scheme'          => json_encode(['type' => 'object']),
            'organization_id' => $this->org->id,
        ]);

        $this->team = Team::create([
            'name'            => 'AR Team',
            'slug'            => 'ar-team',
            'organization_id' => $this->org->id,
            'methodology_id'  => $methodology->id,
        ]);
        $this->team->users()->attach($this->owner);
        $this->team->users()->attach($this->speaker);

        $source = Source::create([
            'user_id'     => $this->owner->id,
            'type'        => 'google_calendar',
            'external_id' => 'ar-src',
            'identity'    => 'owner@example.com',
        ]);

        $this->event = CalendarEvent::create([
            'source_id'    => $source->id,
            'external_id'  => 'ar-event',
            'platform'     => 'google_meet',
            'title'        => 'Author Resolution Meeting',
            'description'  => '',
            'url'          => 'https://meet.google.com/x',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);

        // Привязываем speaker'а к событию через event_profile pivot — стандартный путь резолва.
        $gcChannel = Channel::where('name', 'google_calendar')->firstOrFail();
        $speakerProfile = Profile::create([
            'channel_id'         => $gcChannel->id,
            'channel_identifier' => 'sergey@example.com',
            'user_id'            => $this->speaker->id,
        ]);
        $this->event->profiles()->attach($speakerProfile->id);
    }

    // ── Transcript: автор задачи — резолвнутый говорящий ──

    #[Test]
    public function createIssue_uses_resolved_speaker_as_author(): void
    {
        $this->mockLlmDecisions([
            ['index' => 0, 'action' => 'create'],
        ]);

        $service = $this->app->make(IssueMergeService::class);
        $service->persist(
            [[
                'name'        => 'Fix login bug',
                'description' => '',
                'type'        => 'backend',
                'author_name' => 'Sergey Speaker',
            ]],
            $this->event,
            $this->team,
            $this->owner
        );

        $issue = Issue::firstOrFail();
        $this->assertSame($this->speaker->id, $issue->user_id, 'Author must be resolved speaker, not meeting owner');
    }

    // ── Transcript: speaker не сматчился → fallback на owner ──

    #[Test]
    public function createIssue_falls_back_to_owner_when_speaker_unresolved(): void
    {
        $this->mockLlmDecisions([
            ['index' => 0, 'action' => 'create'],
        ]);

        $service = $this->app->make(IssueMergeService::class);
        $service->persist(
            [[
                'name'        => 'Unclear task',
                'description' => '',
                'type'        => 'backend',
                'author_name' => 'Unknown Person Not In System',
            ]],
            $this->event,
            $this->team,
            $this->owner
        );

        $issue = Issue::firstOrFail();
        $this->assertSame($this->owner->id, $issue->user_id, 'Unresolved speaker must fall back to owner');
    }

    // ── Transcript: author_name=null → fallback на owner ──

    #[Test]
    public function createIssue_falls_back_to_owner_when_author_name_is_null(): void
    {
        $this->mockLlmDecisions([
            ['index' => 0, 'action' => 'create'],
        ]);

        $service = $this->app->make(IssueMergeService::class);
        $service->persist(
            [[
                'name'        => 'Anonymous task',
                'description' => '',
                'type'        => 'backend',
                'author_name' => null,
            ]],
            $this->event,
            $this->team,
            $this->owner
        );

        $issue = Issue::firstOrFail();
        $this->assertSame($this->owner->id, $issue->user_id);
    }

    // ── Update: IssueComment.user_id берётся из резолвнутого автора апдейта ──

    #[Test]
    public function updateIssue_sets_comment_author_to_resolved_speaker(): void
    {
        $existing = Issue::create([
            'user_id'         => $this->owner->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => 'Existing task',
            'type'            => 'backend',
            'status'          => 'open',
        ]);

        $this->mockLlmDecisions([
            [
                'index'              => 0,
                'action'             => 'update',
                'existing_issue_id'  => $existing->id,
                'update_description' => 'New context from Sergey',
                'author_name'        => 'Sergey Speaker',
            ],
        ]);

        $service = $this->app->make(IssueMergeService::class);
        $service->persist(
            [[
                'name'        => 'Same task different wording',
                'description' => '',
                'type'        => 'backend',
            ]],
            $this->event,
            $this->team,
            $this->owner
        );

        $comment = IssueComment::firstOrFail();
        $this->assertSame($this->speaker->id, $comment->user_id);
    }

    // ── Update: author_name=null → user_id=null (бот) ──

    #[Test]
    public function updateIssue_leaves_comment_author_null_when_unresolved(): void
    {
        $existing = Issue::create([
            'user_id'         => $this->owner->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => 'Existing task',
            'type'            => 'backend',
            'status'          => 'open',
        ]);

        $this->mockLlmDecisions([
            [
                'index'              => 0,
                'action'             => 'update',
                'existing_issue_id'  => $existing->id,
                'update_description' => 'Update with no clear speaker',
                'author_name'        => null,
            ],
        ]);

        $service = $this->app->make(IssueMergeService::class);
        $service->persist(
            [[
                'name'        => 'Same task',
                'description' => '',
                'type'        => 'backend',
            ]],
            $this->event,
            $this->team,
            $this->owner
        );

        $comment = IssueComment::firstOrFail();
        $this->assertNull($comment->user_id);
    }

    // ── Telegram: реальный отправитель резолвится через authorIdentity ──

    #[Test]
    public function telegram_task_uses_message_author_identity_as_author(): void
    {
        $conversation = ChannelConversation::create([
            'channel_type'      => ConversationChannelType::TELEGRAM->value,
            'conversation_key'  => ChannelConversation::keyForTelegram(-1001),
            'telegram_chat_id'  => -1001,
            'user_id'           => $this->owner->id,
            'organization_id'   => $this->org->id,
            'team_id'           => $this->team->id,
        ]);

        $identity = ChannelIdentity::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'external_id'  => '999',
            'user_id'      => $this->speaker->id,
            'display_name' => 'Sergey Speaker',
        ]);

        $message = ChannelMessage::create([
            'conversation_id'    => $conversation->id,
            'author_identity_id' => $identity->id,
            'role'               => 'user',
            'content'            => 'Надо починить логин',
        ]);

        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andReturn(json_encode([
            'new_tasks' => [[
                'message_id' => $message->id,
                'title'      => 'Починить логин',
            ]],
            'status_updates' => [],
        ]));
        $this->app->instance(OpenRouterClient::class, $mock);

        $service = $this->app->make(TelegramTaskService::class);
        $service->processChat(-1001);

        $issue = Issue::firstOrFail();
        $this->assertSame($this->speaker->id, $issue->user_id, 'Telegram author must come from authorIdentity.user_id');
    }

    // ── Telegram: external user без authorIdentity.user_id → fallback на conversation.user_id ──

    #[Test]
    public function telegram_task_falls_back_to_conversation_owner_when_identity_has_no_user(): void
    {
        $conversation = ChannelConversation::create([
            'channel_type'     => ConversationChannelType::TELEGRAM->value,
            'conversation_key' => ChannelConversation::keyForTelegram(-1002),
            'telegram_chat_id' => -1002,
            'user_id'          => $this->owner->id,
            'organization_id'  => $this->org->id,
            'team_id'          => $this->team->id,
        ]);

        $identity = ChannelIdentity::create([
            'channel_type' => ConversationChannelType::TELEGRAM->value,
            'external_id'  => '888',
            'user_id'      => null, // внешний участник без аккаунта
            'display_name' => 'External Guest',
        ]);

        $message = ChannelMessage::create([
            'conversation_id'    => $conversation->id,
            'author_identity_id' => $identity->id,
            'role'               => 'user',
            'content'            => 'Сделайте презентацию',
        ]);

        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andReturn(json_encode([
            'new_tasks' => [[
                'message_id' => $message->id,
                'title'      => 'Сделать презентацию',
            ]],
            'status_updates' => [],
        ]));
        $this->app->instance(OpenRouterClient::class, $mock);

        $service = $this->app->make(TelegramTaskService::class);
        $service->processChat(-1002);

        $issue = Issue::firstOrFail();
        $this->assertSame($this->owner->id, $issue->user_id);
    }

    private function mockLlmDecisions(array $decisions): void
    {
        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andReturn(json_encode(['decisions' => $decisions]));
        $this->app->instance(OpenRouterClient::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
