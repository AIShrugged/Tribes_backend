<?php

namespace Tests\Feature;

use App\Events\MeetingArtifactsReady;
use App\Jobs\DetectIssueConflictsJob;
use App\Jobs\NotifyConflictsAuthorJob;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use App\Services\Issue\IssueConflictDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetectIssueConflictsJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_calls_detector_with_issues_and_event(): void
    {
        $org = Organization::create(['name' => 'JC Org', 'slug' => 'jc-org']);
        $author = User::factory()->create();
        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create(['name' => 'Default Methodology', 'text' => 'Default methodology text', 'scheme' => '{}', 'is_default' => true]);
        $team = Team::create(['name' => 'JC Team', 'slug' => 'jc-team', 'organization_id' => $org->id, 'methodology_id' => $methodology->id]);
        $source = Source::create([
            'user_id' => $author->id, 'type' => 'google_calendar',
            'external_id' => 'jc-src', 'identity' => 'a@a.com',
        ]);
        $event = CalendarEvent::create([
            'source_id' => $source->id, 'external_id' => 'jc-event',
            'platform' => 'google_meet', 'title' => 'JC',
            'description' => '', 'url' => 'https://x', 'starts_at' => now(),
            'ends_at' => now()->addHour(), 'required_bot' => false,
        ]);
        $issue = Issue::create([
            'user_id' => $author->id, 'organization_id' => $org->id, 'team_id' => $team->id,
            'name' => 'Y', 'description' => 'd', 'type' => 'development', 'status' => 'open',
        ]);

        $detectorMock = Mockery::mock(IssueConflictDetector::class);
        $detectorMock->shouldReceive('detect')
            ->once()
            ->withArgs(function (array $ids, $eventArg) use ($issue, $event): bool {
                return $ids === [$issue->id]
                    && $eventArg instanceof CalendarEvent
                    && $eventArg->id === $event->id;
            })
            ->andReturn([]);

        (new DetectIssueConflictsJob([$issue->id], $event->id))->handle($detectorMock);
    }

    #[Test]
    public function it_passes_null_event_for_standalone(): void
    {
        $detectorMock = Mockery::mock(IssueConflictDetector::class);
        $detectorMock->shouldReceive('detect')
            ->once()
            ->withArgs(fn (array $ids, $event) => $ids === [123] && $event === null)
            ->andReturn([]);

        (new DetectIssueConflictsJob([123], null))->handle($detectorMock);
    }

    #[Test]
    public function it_returns_early_on_empty_ids_but_still_fires_event_for_meeting(): void
    {
        Event::fake([MeetingArtifactsReady::class]);

        $org = Organization::create(['name' => 'E Org', 'slug' => 'e-org']);
        $author = User::factory()->create();
        $source = Source::create([
            'user_id' => $author->id, 'type' => 'google_calendar',
            'external_id' => 'e-src', 'identity' => 'e@a.com',
        ]);
        $event = CalendarEvent::create([
            'source_id' => $source->id, 'external_id' => 'e-event',
            'platform' => 'google_meet', 'title' => 'E',
            'description' => '', 'url' => 'https://x', 'starts_at' => now(),
            'ends_at' => now()->addHour(), 'required_bot' => false,
        ]);

        $detectorMock = Mockery::mock(IssueConflictDetector::class);
        $detectorMock->shouldNotReceive('detect');

        (new DetectIssueConflictsJob([], $event->id))->handle($detectorMock);

        Event::assertDispatched(
            MeetingArtifactsReady::class,
            fn (MeetingArtifactsReady $e) => $e->event->id === $event->id,
        );
    }

    #[Test]
    public function it_returns_early_on_empty_ids_without_event_for_standalone(): void
    {
        Event::fake([MeetingArtifactsReady::class]);

        $detectorMock = Mockery::mock(IssueConflictDetector::class);
        $detectorMock->shouldNotReceive('detect');

        (new DetectIssueConflictsJob([], null))->handle($detectorMock);

        Event::assertNotDispatched(MeetingArtifactsReady::class);
    }

    #[Test]
    public function it_rethrows_detector_failure_for_retry(): void
    {
        $detectorMock = Mockery::mock(IssueConflictDetector::class);
        $detectorMock->shouldReceive('detect')->andThrow(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);

        (new DetectIssueConflictsJob([1], null))->handle($detectorMock);
    }

    #[Test]
    public function it_fires_meeting_artifacts_ready_on_success(): void
    {
        Event::fake([MeetingArtifactsReady::class]);

        $org = Organization::create(['name' => 'S Org', 'slug' => 's-org']);
        $author = User::factory()->create();
        $source = Source::create([
            'user_id' => $author->id, 'type' => 'google_calendar',
            'external_id' => 's-src', 'identity' => 's@a.com',
        ]);
        $event = CalendarEvent::create([
            'source_id' => $source->id, 'external_id' => 's-event',
            'platform' => 'google_meet', 'title' => 'S',
            'description' => '', 'url' => 'https://x', 'starts_at' => now(),
            'ends_at' => now()->addHour(), 'required_bot' => false,
        ]);

        $detectorMock = Mockery::mock(IssueConflictDetector::class);
        $detectorMock->shouldReceive('detect')->andReturn([]);

        (new DetectIssueConflictsJob([1], $event->id))->handle($detectorMock);

        Event::assertDispatched(
            MeetingArtifactsReady::class,
            fn (MeetingArtifactsReady $e) => $e->event->id === $event->id,
        );
    }

    #[Test]
    public function it_fires_meeting_artifacts_ready_via_failed_callback(): void
    {
        Event::fake([MeetingArtifactsReady::class]);

        $org = Organization::create(['name' => 'F Org', 'slug' => 'f-org']);
        $author = User::factory()->create();
        $source = Source::create([
            'user_id' => $author->id, 'type' => 'google_calendar',
            'external_id' => 'f-src', 'identity' => 'f@a.com',
        ]);
        $event = CalendarEvent::create([
            'source_id' => $source->id, 'external_id' => 'f-event',
            'platform' => 'google_meet', 'title' => 'F',
            'description' => '', 'url' => 'https://x', 'starts_at' => now(),
            'ends_at' => now()->addHour(), 'required_bot' => false,
        ]);

        (new DetectIssueConflictsJob([1], $event->id))
            ->failed(new \RuntimeException('all retries done'));

        Event::assertDispatched(
            MeetingArtifactsReady::class,
            fn (MeetingArtifactsReady $e) => $e->event->id === $event->id,
        );
    }

    #[Test]
    public function it_does_not_fire_event_for_standalone_success(): void
    {
        Event::fake([MeetingArtifactsReady::class]);

        $detectorMock = Mockery::mock(IssueConflictDetector::class);
        $detectorMock->shouldReceive('detect')->andReturn([]);

        (new DetectIssueConflictsJob([1], null))->handle($detectorMock);

        Event::assertNotDispatched(MeetingArtifactsReady::class);
    }

    #[Test]
    public function it_dispatches_author_notifier_for_standalone_groups(): void
    {
        Bus::fake([NotifyConflictsAuthorJob::class]);

        $detectorMock = Mockery::mock(IssueConflictDetector::class);
        $detectorMock->shouldReceive('detect')->andReturn([
            ['group_uuid' => 'g-1', 'members' => [['issue_id' => 1]], 'fields' => ['due_date'], 'summary' => 's1'],
            ['group_uuid' => 'g-2', 'members' => [['issue_id' => 2]], 'fields' => ['assignee'], 'summary' => 's2'],
        ]);

        (new DetectIssueConflictsJob([1, 2], null))->handle($detectorMock);

        Bus::assertDispatched(
            NotifyConflictsAuthorJob::class,
            fn (NotifyConflictsAuthorJob $job) =>
                $job->conflictGroupUuids === ['g-1', 'g-2'],
        );
    }

    #[Test]
    public function it_does_not_dispatch_author_notifier_for_meeting_groups(): void
    {
        Bus::fake([NotifyConflictsAuthorJob::class]);

        $org = Organization::create(['name' => 'NA Org', 'slug' => 'na-org']);
        $author = User::factory()->create();
        $source = Source::create([
            'user_id' => $author->id, 'type' => 'google_calendar',
            'external_id' => 'na-src', 'identity' => 'a@a.com',
        ]);
        $event = CalendarEvent::create([
            'source_id' => $source->id, 'external_id' => 'na-event',
            'platform' => 'google_meet', 'title' => 'NA',
            'description' => '', 'url' => 'https://x', 'starts_at' => now(),
            'ends_at' => now()->addHour(), 'required_bot' => false,
        ]);

        $detectorMock = Mockery::mock(IssueConflictDetector::class);
        $detectorMock->shouldReceive('detect')->andReturn([
            ['group_uuid' => 'g-m1', 'members' => [['issue_id' => 1]], 'fields' => ['due_date'], 'summary' => 'm'],
        ]);

        (new DetectIssueConflictsJob([1], $event->id))->handle($detectorMock);

        Bus::assertNotDispatched(NotifyConflictsAuthorJob::class);
    }

    #[Test]
    public function it_does_not_dispatch_when_no_groups_found(): void
    {
        Bus::fake([NotifyConflictsAuthorJob::class]);

        $detectorMock = Mockery::mock(IssueConflictDetector::class);
        $detectorMock->shouldReceive('detect')->andReturn([]);

        (new DetectIssueConflictsJob([1], null))->handle($detectorMock);

        Bus::assertNotDispatched(NotifyConflictsAuthorJob::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
