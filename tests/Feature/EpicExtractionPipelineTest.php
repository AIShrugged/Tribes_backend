<?php

namespace Tests\Feature;

use App\Jobs\ExtractEpicsFromTranscriptJob;
use App\Jobs\VerifyMeetingArtifactsJob;
use App\Models\CalendarEvent;
use App\Models\Channel;
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
use App\Services\Issue\EpicExtractionService;
use App\Services\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpicExtractionPipelineTest extends TestCase
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

        $this->org = Organization::create(['name' => 'E Org', 'slug' => 'e-org']);

        $this->owner = User::factory()->create(['name' => 'Olga Owner']);
        $this->speaker = User::factory()->create(['name' => 'Sergey Speaker']);
        $this->org->users()->attach($this->owner, ['role' => 'employee']);
        $this->org->users()->attach($this->speaker, ['role' => 'employee']);

        $methodology = Methodology::create([
            'name' => 'M', 'text' => 'M', 'scheme' => json_encode(['type' => 'object']),
            'organization_id' => $this->org->id,
        ]);

        $this->team = Team::create([
            'name' => 'E Team', 'slug' => 'e-team',
            'organization_id' => $this->org->id,
            'methodology_id'  => $methodology->id,
        ]);
        $this->team->users()->attach($this->owner);
        $this->team->users()->attach($this->speaker);

        $source = Source::create([
            'user_id'     => $this->owner->id,
            'type'        => 'google_calendar',
            'external_id' => 'e-src',
            'identity'    => 'owner@example.com',
        ]);

        $this->event = CalendarEvent::create([
            'source_id'    => $source->id,
            'external_id'  => 'e-event',
            'platform'     => 'google_meet',
            'title'        => 'Epic Extraction Meeting',
            'description'  => '',
            'url'          => 'https://meet.google.com/x',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);

        $gcChannel = Channel::where('name', 'google_calendar')->firstOrFail();
        $speakerProfile = Profile::create([
            'channel_id'         => $gcChannel->id,
            'channel_identifier' => 'sergey@example.com',
            'user_id'            => $this->speaker->id,
        ]);
        $this->event->profiles()->attach($speakerProfile->id);

        // Seed a minimal transcript so EpicExtractionService doesn't return empty.
        $participant = Participant::create([
            'calendar_event_id' => $this->event->id,
            'name'              => 'Sergey Speaker',
            'profile_id'        => $speakerProfile->id,
        ]);
        TranscriptEntry::create([
            'calendar_event_id' => $this->event->id,
            'participant_id'    => $participant->id,
            'text'              => 'Sergey: к концу квартала хотим запустить ROI tracking по всем кампаниям',
            'start_relative'    => 0.0,
            'end_relative'      => 5.0,
            'start_absolute'    => now(),
            'end_absolute'      => now()->addSeconds(5),
        ]);
    }

    #[Test]
    public function extract_creates_new_epic_and_links_children(): void
    {
        $child = $this->makeMeetingIssue('Build ROI export to PDF');

        $this->mockLlmEpics([[
            'action'          => 'create',
            'existing_epic_id' => null,
            'name'            => 'ROI tracking for campaigns',
            'description'     => "## Контекст\nROI tracking требуется к концу Q.\n\n## Пункты\n1. Сбор данных\n2. Дашборд",
            'scope'           => 'team',
            'author_name'     => 'Sergey Speaker',
            'child_issue_ids' => [$child->id],
        ]]);

        $service = $this->app->make(EpicExtractionService::class);
        $result = $service->extract($this->event, $this->team, $this->owner, collect([$child]));

        $this->assertCount(1, $result['created']);
        $this->assertCount(0, $result['updated']);

        $epic = $result['created']->first();
        $this->assertSame(Issue::TYPE_EPIC, $epic->type);
        $this->assertSame('ROI tracking for campaigns', $epic->name);
        $this->assertSame($this->team->id, $epic->team_id);
        $this->assertSame($this->speaker->id, $epic->user_id, 'Epic author must be resolved speaker');
        $this->assertStringContainsString('## Контекст', $epic->description);
        $this->assertStringContainsString('## Пункты', $epic->description);
        $this->assertStringNotContainsString('Definition of done', $epic->description);

        $child->refresh();
        $this->assertSame($epic->id, $child->epic_id, 'Child issue must be linked to new epic via epic_id');
    }

    #[Test]
    public function extract_organization_scope_clears_team_id(): void
    {
        $child = $this->makeMeetingIssue('Some company-wide task');

        $this->mockLlmEpics([[
            'action'          => 'create',
            'name'            => 'Make Wanda the de-facto HR tool',
            'description'     => "## Контекст\nКомпания-уровень.\n\n## Пункты\n1. Adoption",
            'scope'           => 'organization',
            'author_name'     => null,
            'child_issue_ids' => [$child->id],
        ]]);

        $service = $this->app->make(EpicExtractionService::class);
        $result = $service->extract($this->event, $this->team, $this->owner, collect([$child]));

        $epic = $result['created']->first();
        $this->assertNull($epic->team_id);
        $this->assertSame($this->org->id, $epic->organization_id);
    }

    #[Test]
    public function extract_updates_existing_epic_and_adds_comment(): void
    {
        $existingEpic = Issue::create([
            'user_id'         => $this->owner->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => 'Old epic',
            'description'     => "## Контекст\nстарый\n\n## Пункты\n1. one",
            'type'            => Issue::TYPE_EPIC,
            'status'          => 'open',
        ]);
        $child = $this->makeMeetingIssue('Update step');

        $this->mockLlmEpics([[
            'action'             => 'update',
            'existing_epic_id'   => $existingEpic->id,
            'name'               => 'New name proposed by LLM',
            'description'        => "## Контекст\nновый контекст\n\n## Пункты\n1. one\n2. two",
            'scope'              => 'team',
            'author_name'        => 'Sergey Speaker',
            'update_description' => 'Добавили пункт 2 из обсуждения',
            'child_issue_ids'    => [$child->id],
        ]]);

        $service = $this->app->make(EpicExtractionService::class);
        $result = $service->extract($this->event, $this->team, $this->owner, collect([$child]));

        $this->assertCount(0, $result['created']);
        $this->assertCount(1, $result['updated']);

        $existingEpic->refresh();
        // US-7.1: name/description on existing epic must remain immutable during merge.
        $this->assertSame('Old epic', $existingEpic->name);
        $this->assertSame("## Контекст\nстарый\n\n## Пункты\n1. one", $existingEpic->description);

        $comment = IssueComment::firstOrFail();
        $this->assertSame($existingEpic->id, $comment->issue_id);
        $this->assertSame($this->speaker->id, $comment->user_id, 'Comment author must be resolved speaker');
        $this->assertStringContainsString('Добавили пункт 2', $comment->content);

        $child->refresh();
        $this->assertSame($existingEpic->id, $child->epic_id);
    }

    #[Test]
    public function extract_update_with_blank_update_description_creates_no_comment(): void
    {
        $existingEpic = Issue::create([
            'user_id'         => $this->owner->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => 'Old epic',
            'description'     => "## Контекст\nстарый\n\n## Пункты\n1. one",
            'type'            => Issue::TYPE_EPIC,
            'status'          => 'open',
        ]);
        $child = $this->makeMeetingIssue('Step');

        $this->mockLlmEpics([[
            'action'             => 'update',
            'existing_epic_id'   => $existingEpic->id,
            'name'               => 'Old epic',
            'description'        => "## Контекст\nстарый\n\n## Пункты\n1. one",
            'scope'              => 'team',
            'author_name'        => null,
            'update_description' => '',
            'child_issue_ids'    => [$child->id],
        ]]);

        $service = $this->app->make(EpicExtractionService::class);
        $result = $service->extract($this->event, $this->team, $this->owner, collect([$child]));

        $this->assertCount(1, $result['updated']);
        $this->assertSame(0, IssueComment::count(), 'Blank update_description must not produce a comment');

        $existingEpic->refresh();
        $this->assertSame('Old epic', $existingEpic->name);

        $child->refresh();
        $this->assertSame($existingEpic->id, $child->epic_id);
    }

    #[Test]
    public function extract_skip_action_creates_nothing(): void
    {
        $existingEpic = Issue::create([
            'user_id'         => $this->owner->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'name'            => 'Existing epic',
            'type'            => Issue::TYPE_EPIC,
            'status'          => 'open',
        ]);
        $child = $this->makeMeetingIssue('Some task');

        $this->mockLlmEpics([[
            'action' => 'skip',
        ]]);

        $service = $this->app->make(EpicExtractionService::class);
        $result = $service->extract($this->event, $this->team, $this->owner, collect([$child]));

        $this->assertCount(0, $result['created']);
        $this->assertCount(0, $result['updated']);
        $this->assertSame(1, Issue::where('type', Issue::TYPE_EPIC)->count(), 'No new epics created');

        $child->refresh();
        $this->assertNull($child->epic_id, 'Child stays unlinked when action=skip');
    }

    #[Test]
    public function extract_returns_empty_when_no_meeting_issues(): void
    {
        $service = $this->app->make(EpicExtractionService::class);
        $result = $service->extract($this->event, $this->team, $this->owner, collect());

        $this->assertEmpty($result['created']);
        $this->assertEmpty($result['updated']);
    }

    #[Test]
    public function extract_returns_empty_on_llm_failure(): void
    {
        $child = $this->makeMeetingIssue('Some task');

        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andThrow(new \RuntimeException('API down'));
        $this->app->instance(OpenRouterClient::class, $mock);

        $service = $this->app->make(EpicExtractionService::class);
        $result = $service->extract($this->event, $this->team, $this->owner, collect([$child]));

        $this->assertEmpty($result['created']);
        $this->assertEmpty($result['updated']);
        $this->assertSame(0, Issue::where('type', Issue::TYPE_EPIC)->count());
    }

    #[Test]
    public function llm_returning_invalid_existing_epic_id_falls_back_to_create(): void
    {
        $child = $this->makeMeetingIssue('Step');

        $this->mockLlmEpics([[
            'action'           => 'update',
            'existing_epic_id' => 99999, // несуществующий
            'name'             => 'Phantom update',
            'description'      => "## Контекст\nx\n\n## Пункты\n1. y",
            'scope'            => 'team',
            'author_name'      => null,
            'child_issue_ids'  => [$child->id],
        ]]);

        $service = $this->app->make(EpicExtractionService::class);
        $result = $service->extract($this->event, $this->team, $this->owner, collect([$child]));

        $this->assertCount(1, $result['created'], 'Invalid existing_epic_id must fall back to create');
        $this->assertCount(0, $result['updated']);
    }

    #[Test]
    public function llm_returning_invalid_child_issue_ids_links_only_valid_ones(): void
    {
        $realChild = $this->makeMeetingIssue('Real task');

        $this->mockLlmEpics([[
            'action'           => 'create',
            'name'             => 'Epic with mixed children',
            'description'      => "## Контекст\nx\n\n## Пункты\n1. y",
            'scope'            => 'team',
            'author_name'      => null,
            'child_issue_ids'  => [$realChild->id, 88888, 99999],
        ]]);

        $service = $this->app->make(EpicExtractionService::class);
        $result = $service->extract($this->event, $this->team, $this->owner, collect([$realChild]));

        $epic = $result['created']->first();
        $realChild->refresh();
        $this->assertSame($epic->id, $realChild->epic_id);
    }

    // ── Wire-up: VerifyMeetingArtifactsJob диспатчит ExtractEpicsFromTranscriptJob ──

    #[Test]
    public function verify_meeting_artifacts_job_dispatches_extract_epics_job(): void
    {
        Queue::fake();

        // Pre-seed valid summary so ensureSummary не запустит LLM, и не нужно мокать.
        \App\Models\MeetingSummary::create([
            'calendar_event_id' => $this->event->id,
            'status'            => \App\Enums\FollowupStatus::DONE->value,
            'summary'           => 'pre-seeded summary',
            'key_points'        => ['kp1'],
            'decisions'         => ['d1'],
        ]);

        $job = new VerifyMeetingArtifactsJob($this->event, $this->team, $this->owner);
        $job->handle(
            $this->app->make(\App\Services\Meeting\MeetingSummaryService::class),
            $this->app->make(\App\Services\IssueMergeService::class),
            $this->app->make(\App\Services\Issue\IncompleteIssuesNotifier::class),
        );

        Queue::assertPushed(ExtractEpicsFromTranscriptJob::class, function ($pushed) {
            return $pushed->event->id === $this->event->id
                && $pushed->team->id === $this->team->id
                && $pushed->user->id === $this->owner->id;
        });
    }

    private function makeMeetingIssue(string $name): Issue
    {
        return Issue::create([
            'user_id'         => $this->owner->id,
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id'   => $this->event->id,
            'name'            => $name,
            'type'            => 'organization',
            'status'          => 'open',
        ]);
    }

    private function mockLlmEpics(array $epics): void
    {
        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andReturn(json_encode(['epics' => $epics]));
        $this->app->instance(OpenRouterClient::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
