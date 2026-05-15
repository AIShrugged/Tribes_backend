<?php

namespace Tests\Feature;

use App\Events\MeetingArtifactsReady;
use App\Jobs\DetectIssueConflictsJob;
use App\Jobs\ValidateAutoIssuesContentJob;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Source;
use App\Models\User;
use App\Services\Issue\IssueAutoPipelineDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IssueAutoPipelineDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private CalendarEvent $event;
    private User $author;
    private User $demoAuthor;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Disp Org', 'slug' => 'disp-org']);
        $this->author = User::factory()->create();
        $this->demoAuthor = User::factory()->create();
        $this->demoAuthor->forceFill(['is_demo' => true])->save();

        $source = Source::create([
            'user_id'     => $this->author->id,
            'type'        => 'google_calendar',
            'external_id' => 'disp-src',
            'identity'    => 'a@example.com',
        ]);

        $this->event = CalendarEvent::create([
            'source_id'    => $source->id,
            'external_id'  => 'disp-event',
            'platform'     => 'google_meet',
            'title'        => 'Planning',
            'description'  => '',
            'url'          => 'https://meet.google.com/y',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);
    }

    #[Test]
    public function dispatch_for_meeting_filters_demo_users(): void
    {
        Bus::fake([ValidateAutoIssuesContentJob::class, DetectIssueConflictsJob::class]);

        $regularIssue = Issue::create([
            'user_id'         => $this->author->id,
            'organization_id' => $this->org->id,
            'name'            => 'Regular',
            'type'            => 'development',
            'status'          => 'open',
        ]);

        $demoIssue = Issue::create([
            'user_id'         => $this->demoAuthor->id,
            'organization_id' => $this->org->id,
            'name'            => 'Demo',
            'type'            => 'development',
            'status'          => 'open',
        ]);

        app(IssueAutoPipelineDispatcher::class)->dispatchForMeeting(
            $this->event,
            [$regularIssue->id, $demoIssue->id],
        );

        Bus::assertDispatched(
            ValidateAutoIssuesContentJob::class,
            fn (ValidateAutoIssuesContentJob $job) =>
                $job->issueIds === [$regularIssue->id],
        );
        Bus::assertDispatched(
            DetectIssueConflictsJob::class,
            fn (DetectIssueConflictsJob $job) =>
                $job->newIssueIds === [$regularIssue->id]
                && $job->detectedInCalendarEventId === $this->event->id,
        );
    }

    #[Test]
    public function dispatch_for_meeting_skips_jobs_but_fires_event_when_all_issues_demo(): void
    {
        Bus::fake([ValidateAutoIssuesContentJob::class, DetectIssueConflictsJob::class]);
        Event::fake([MeetingArtifactsReady::class]);

        $demoIssue = Issue::create([
            'user_id'         => $this->demoAuthor->id,
            'organization_id' => $this->org->id,
            'name'            => 'Demo only',
            'type'            => 'development',
            'status'          => 'open',
        ]);

        app(IssueAutoPipelineDispatcher::class)->dispatchForMeeting(
            $this->event,
            [$demoIssue->id],
        );

        Bus::assertNotDispatched(ValidateAutoIssuesContentJob::class);
        Bus::assertNotDispatched(DetectIssueConflictsJob::class);
        // Summary listener must still fire — detector wouldn't run to emit it.
        Event::assertDispatched(
            MeetingArtifactsReady::class,
            fn (MeetingArtifactsReady $e) => $e->event->id === $this->event->id,
        );
    }

    #[Test]
    public function dispatch_for_standalone_filters_demo_users(): void
    {
        Bus::fake([ValidateAutoIssuesContentJob::class, DetectIssueConflictsJob::class]);

        $regularIssue = Issue::create([
            'user_id'         => $this->author->id,
            'organization_id' => $this->org->id,
            'name'            => 'Standalone regular',
            'type'            => 'development',
            'status'          => 'open',
        ]);

        $demoIssue = Issue::create([
            'user_id'         => $this->demoAuthor->id,
            'organization_id' => $this->org->id,
            'name'            => 'Standalone demo',
            'type'            => 'development',
            'status'          => 'open',
        ]);

        app(IssueAutoPipelineDispatcher::class)->dispatchForStandalone(
            [$regularIssue->id, $demoIssue->id],
        );

        Bus::assertDispatched(
            ValidateAutoIssuesContentJob::class,
            fn (ValidateAutoIssuesContentJob $job) =>
                $job->issueIds === [$regularIssue->id],
        );
        Bus::assertDispatched(
            DetectIssueConflictsJob::class,
            fn (DetectIssueConflictsJob $job) =>
                $job->newIssueIds === [$regularIssue->id]
                && $job->detectedInCalendarEventId === null,
        );
    }

    #[Test]
    public function dispatch_for_standalone_handles_empty_input(): void
    {
        Bus::fake([ValidateAutoIssuesContentJob::class, DetectIssueConflictsJob::class]);

        app(IssueAutoPipelineDispatcher::class)->dispatchForStandalone([]);

        Bus::assertNotDispatched(ValidateAutoIssuesContentJob::class);
        Bus::assertNotDispatched(DetectIssueConflictsJob::class);
    }

    #[Test]
    public function dispatch_for_meeting_handles_missing_ids_gracefully(): void
    {
        Bus::fake([ValidateAutoIssuesContentJob::class, DetectIssueConflictsJob::class]);
        Event::fake([MeetingArtifactsReady::class]);

        app(IssueAutoPipelineDispatcher::class)->dispatchForMeeting(
            $this->event,
            [99999], // non-existent
        );

        Bus::assertNotDispatched(ValidateAutoIssuesContentJob::class);
        Bus::assertNotDispatched(DetectIssueConflictsJob::class);
        Event::assertDispatched(
            MeetingArtifactsReady::class,
            fn (MeetingArtifactsReady $e) => $e->event->id === $this->event->id,
        );
    }

    #[Test]
    public function dispatch_for_meeting_with_eligible_does_not_fire_event_directly(): void
    {
        // When eligible issues exist, the detector job is responsible for emitting
        // MeetingArtifactsReady at its end — the dispatcher must NOT emit it here,
        // otherwise the summary would render before conflicts persist.
        Bus::fake([ValidateAutoIssuesContentJob::class, DetectIssueConflictsJob::class]);
        Event::fake([MeetingArtifactsReady::class]);

        $regularIssue = Issue::create([
            'user_id'         => $this->author->id,
            'organization_id' => $this->org->id,
            'name'            => 'Regular',
            'type'            => 'development',
            'status'          => 'open',
        ]);

        app(IssueAutoPipelineDispatcher::class)->dispatchForMeeting(
            $this->event,
            [$regularIssue->id],
        );

        Bus::assertDispatched(DetectIssueConflictsJob::class);
        Event::assertNotDispatched(MeetingArtifactsReady::class);
    }
}
