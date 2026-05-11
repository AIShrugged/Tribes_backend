<?php

namespace Tests\Feature;

use App\Jobs\VerifyMeetingArtifactsJob;
use App\Models\CalendarEvent;
use App\Models\Decision;
use App\Models\MeetingSummary;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use App\Services\Issue\IncompleteIssuesNotifier;
use App\Services\IssueMergeService;
use App\Services\Meeting\MeetingSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VerifyMeetingArtifactsJobTest extends TestCase
{
    use RefreshDatabase;

    private CalendarEvent $event;
    private Team $team;
    private User $user;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org  = Organization::create(['name' => 'Verify Org', 'slug' => 'verify-org']);
        $this->user = User::factory()->create();
        $this->org->users()->attach($this->user, ['role' => 'employee']);

        $methodology = Methodology::create([
            'name'            => 'Test',
            'text'            => 'Test methodology.',
            'scheme'          => json_encode(['type' => 'object']),
            'organization_id' => $this->org->id,
        ]);

        $this->team = Team::create([
            'name'            => 'Verify Team',
            'slug'            => 'verify-team',
            'organization_id' => $this->org->id,
            'methodology_id'  => $methodology->id,
        ]);
        $this->team->users()->attach($this->user);

        $source = Source::create([
            'user_id'     => $this->user->id,
            'type'        => 'google_calendar',
            'external_id' => 'verify-src',
            'identity'    => 'user@example.com',
        ]);

        $this->event = CalendarEvent::create([
            'source_id'    => $source->id,
            'external_id'  => 'verify-event',
            'platform'     => 'google_meet',
            'title'        => 'Q1 Planning',
            'description'  => '',
            'url'          => 'https://meet.google.com/x',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);
    }

    private function runJob(?MeetingSummaryService $summaryMock = null): void
    {
        $job = new VerifyMeetingArtifactsJob($this->event, $this->team, $this->user);
        $job->handle(
            $summaryMock ?? $this->app->make(MeetingSummaryService::class),
            $this->app->make(IssueMergeService::class),
            $this->app->make(IncompleteIssuesNotifier::class),
        );
    }

    private function createCompleteSummary(): MeetingSummary
    {
        return MeetingSummary::create([
            'calendar_event_id' => $this->event->id,
            'status'            => 'ready',
            'title'             => 'Q1 Planning',
            'summary'           => 'Discussed goals.',
            'key_points'        => ['Point A'],
            'decisions'         => ['Deploy by Friday'],
        ]);
    }

    // ── 1. Missing summary triggers MeetingSummaryService::generate ──

    #[Test]
    public function missing_summary_triggers_regeneration(): void
    {
        $mockSummary = Mockery::mock(MeetingSummaryService::class);
        $mockSummary->shouldReceive('generate')->once()->with(Mockery::on(
            fn ($e) => $e->id === $this->event->id
        ));

        $this->runJob($mockSummary);

        // No real summary was persisted (mock did nothing on generate)
        $this->assertDatabaseCount('meeting_summaries', 0);
    }

    #[Test]
    public function complete_summary_skips_regeneration(): void
    {
        $this->createCompleteSummary();

        $mockSummary = Mockery::mock(MeetingSummaryService::class);
        $mockSummary->shouldNotReceive('generate');

        $this->runJob($mockSummary);

        // Pre-created summary still the only one
        $this->assertDatabaseCount('meeting_summaries', 1);
    }

    // ── 2. Uncovered decision creates issue and decision_issue link ──

    #[Test]
    public function uncovered_decision_creates_issue_and_gets_linked(): void
    {
        $this->createCompleteSummary();

        $decision = Decision::create([
            'calendar_event_id' => $this->event->id,
            'team_id'           => $this->team->id,
            'organization_id'   => $this->org->id,
            'text'              => 'We will migrate to new auth provider by end of month.',
        ]);

        $mockSummary = Mockery::mock(MeetingSummaryService::class);
        $mockSummary->shouldNotReceive('generate');

        $this->runJob($mockSummary);

        $this->assertDatabaseCount('issues', 1);
        $this->assertTrue(
            DB::table('decision_issue')->where('decision_id', $decision->id)->exists(),
            'decision_issue link was not created'
        );
    }

    // ── 3. Decision-issue link not duplicated on rerun ──

    #[Test]
    public function decision_issue_link_not_duplicated_on_rerun(): void
    {
        $this->createCompleteSummary();

        $decision = Decision::create([
            'calendar_event_id' => $this->event->id,
            'team_id'           => $this->team->id,
            'organization_id'   => $this->org->id,
            'text'              => 'Migrate CI to GitHub Actions.',
        ]);

        $mockSummary = Mockery::mock(MeetingSummaryService::class);
        $mockSummary->shouldNotReceive('generate');

        $this->runJob($mockSummary);
        $this->runJob($mockSummary);

        $linkCount = DB::table('decision_issue')->where('decision_id', $decision->id)->count();
        $this->assertEquals(1, $linkCount, 'decision_issue was duplicated on second run');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
