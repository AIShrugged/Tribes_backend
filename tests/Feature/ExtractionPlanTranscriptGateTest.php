<?php

namespace Tests\Feature;

use App\Jobs\ExtractIssuesFromTranscriptJob;
use App\Jobs\VerifyMeetingArtifactsJob;
use App\Models\CalendarEvent;
use App\Models\ExtractionPlan;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use App\Services\Followup\TranscriptBuilderService;
use App\Services\IssueExtractionService;
use App\Services\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Moderation must trigger for ANY manual admin upload — including attaching a transcript to an
 * EXISTING (Recall) meeting, whose event keeps its non-manual platform. So the gate keys on the
 * plan's EXISTENCE (created by the manual-upload controller), NOT on platform === 'manual_upload'.
 */
class ExtractionPlanTranscriptGateTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private User $user;
    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Gate Org', 'slug' => 'gate-org']);
        $this->user = User::factory()->create();
        $org->users()->attach($this->user, ['role' => 'employee']);
        $methodology = Methodology::create([
            'name' => 'M', 'text' => 'M.', 'scheme' => json_encode(['type' => 'object']),
            'organization_id' => $org->id,
        ]);
        $this->team = Team::create([
            'name' => 'Gate Team', 'slug' => 'gate-team',
            'organization_id' => $org->id, 'methodology_id' => $methodology->id,
        ]);
        $this->team->users()->attach($this->user);
        $source = Source::create([
            'user_id' => $this->user->id, 'type' => 'google_calendar',
            'external_id' => 'gate-src', 'identity' => 'g@x.com', 'organization_id' => $org->id,
        ]);

        // NON-manual platform on purpose: simulates "attach transcript to an existing Recall meeting".
        $this->event = CalendarEvent::create([
            'source_id' => $source->id, 'external_id' => 'gate-evt', 'platform' => 'google_meet',
            'title' => 'Existing meeting', 'description' => '', 'url' => 'https://meet.google.com/g',
            'starts_at' => now(), 'ends_at' => now()->addHour(), 'required_bot' => false,
        ]);

        $transcriptBuilder = Mockery::mock(TranscriptBuilderService::class);
        $transcriptBuilder->shouldReceive('build')->andReturn('Alice: we will do X by Friday.');
        $this->app->instance(TranscriptBuilderService::class, $transcriptBuilder);

        $llm = Mockery::mock(OpenRouterClient::class);
        $llm->shouldReceive('chat')->andReturn(json_encode([
            'issues' => [['name' => 'Do X', 'description' => 'd', 'type' => 'backend']],
        ]));
        $this->app->instance(OpenRouterClient::class, $llm);
    }

    private function runIssuesJob(): void
    {
        (new ExtractIssuesFromTranscriptJob($this->event, $this->team, $this->user))
            ->handle($this->app->make(IssueExtractionService::class));
    }

    #[Test]
    public function gated_when_a_collecting_plan_exists_even_for_non_manual_platform(): void
    {
        Queue::fake();

        ExtractionPlan::create([
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $this->event->id,
            'team_id' => $this->team->id,
            'organization_id' => $this->team->organization_id,
            'user_id' => $this->user->id,
            'status' => ExtractionPlan::STATUS_COLLECTING,
            'expected_sections' => ['issues'],
            'section_status' => [],
            'plan' => ['issues' => null, 'decisions' => null, 'review' => null],
        ]);

        $this->runIssuesJob();

        // Staged, not persisted; downstream NOT replayed.
        $this->assertDatabaseCount('issues', 0);
        Queue::assertNotPushed(VerifyMeetingArtifactsJob::class);

        $plan = ExtractionPlan::where('sourceable_id', $this->event->id)->firstOrFail();
        $this->assertSame('ready', $plan->section_status['issues']);
        $this->assertSame('pending_review', $plan->status); // single expected section ready → flips
        $this->assertCount(1, $plan->plan['issues']['items']);
    }

    #[Test]
    public function not_gated_when_no_plan_exists(): void
    {
        Queue::fake();

        $this->runIssuesJob();

        // Normal path: issue persisted + downstream replayed; no plan created.
        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseCount('extraction_plans', 0);
        Queue::assertPushed(VerifyMeetingArtifactsJob::class);
    }

    #[Test]
    public function failed_plan_does_not_leak_issues_to_the_live_pipeline(): void
    {
        // Simulates the summary branch failing first and marking the plan 'failed' before the issues
        // job runs. The issues job must NOT fall through to the live pipeline (persist + notify).
        Queue::fake();

        ExtractionPlan::create([
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $this->event->id,
            'team_id' => $this->team->id,
            'organization_id' => $this->team->organization_id,
            'user_id' => $this->user->id,
            'status' => ExtractionPlan::STATUS_FAILED,
            'expected_sections' => ['issues', 'decisions'],
            'section_status' => ['decisions' => 'failed'],
            'plan' => ['issues' => null, 'decisions' => null, 'review' => null],
        ]);

        $this->runIssuesJob();

        $this->assertDatabaseCount('issues', 0);
        Queue::assertNotPushed(VerifyMeetingArtifactsJob::class);
    }
}
