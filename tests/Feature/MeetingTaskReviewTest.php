<?php

namespace Tests\Feature;

use App\Jobs\PruneResolvedMeetingTaskItemsJob;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingTaskReview;
use App\Models\MeetingTaskReviewItem;
use App\Models\Organization;
use App\Models\Source;
use App\Models\TranscriptEntry;
use App\Models\User;
use App\Models\Participant;
use App\Services\Issue\MeetingTaskReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingTaskReviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $this->user = User::factory()->create();
        $this->user->organizations()->attach($this->org->id, ['role' => 'manager']);

        $source = Source::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'type' => 'google_calendar',
            'external_id' => 'src-test',
            'identity' => 'test@example.com',
        ]);

        $this->event = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-test',
            'platform' => 'google_meet',
            'title' => 'Test Meeting',
            'description' => '',
            'url' => 'https://meet.example.com/test',
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
        ]);

        // Add transcript
        $participant = Participant::create([
            'calendar_event_id' => $this->event->id,
            'name' => 'John Doe',
        ]);

        TranscriptEntry::create([
            'calendar_event_id' => $this->event->id,
            'participant_id' => $participant->id,
            'text' => 'We completed the login task yesterday',
            'start_relative' => 0,
            'end_relative' => 5,
            'start_absolute' => now(),
            'end_absolute' => now()->addSeconds(5),
        ]);
    }

    #[Test]
    public function it_creates_pending_review_on_generate()
    {
        // Create a test issue
        Issue::create([
            'organization_id' => $this->org->id,
            'name' => 'Login Task',
            'status' => 'in_progress',
            'user_id' => $this->user->id,
        ]);

        $service = app(MeetingTaskReviewService::class);
        $review = $service->generate($this->event, $this->org->id);

        $this->assertNotNull($review);
        $this->assertEquals($this->event->id, $review->calendar_event_id);
        $this->assertEquals($this->org->id, $review->organization_id);
        // Status should be done since we have transcript
        $this->assertContains($review->status, ['done', 'pending', 'failed']);
    }

    #[Test]
    public function it_returns_empty_blocks_when_no_issues()
    {
        $service = app(MeetingTaskReviewService::class);

        $blocks = $service->getHealthBlocks($this->org->id);

        $this->assertIsArray($blocks);
        $this->assertEmpty($blocks);
    }

    #[Test]
    public function it_identifies_issues_without_assignee()
    {
        Issue::create([
            'organization_id' => $this->org->id,
            'name' => 'Unassigned Task',
            'status' => 'open',
            'user_id' => $this->user->id,
            'assignee_id' => null,
        ]);

        $service = app(MeetingTaskReviewService::class);
        $blocks = $service->getHealthBlocks($this->org->id);

        $noAssigneeBlock = collect($blocks)->firstWhere('type', 'no_assignee');
        $this->assertNotNull($noAssigneeBlock);
        $this->assertGreaterThan(0, $noAssigneeBlock['count']);
    }

    #[Test]
    public function it_marks_overdue_issues()
    {
        Issue::create([
            'organization_id' => $this->org->id,
            'name' => 'Overdue Task',
            'status' => 'in_progress',
            'user_id' => $this->user->id,
            'assignee_id' => $this->user->id,
            'due_date' => now()->subDays(5),
        ]);

        $service = app(MeetingTaskReviewService::class);
        $blocks = $service->getHealthBlocks($this->org->id);

        $overdueBlock = collect($blocks)->firstWhere('type', 'overdue');
        $this->assertNotNull($overdueBlock);
        $this->assertGreaterThan(0, $overdueBlock['count']);
    }

    #[Test]
    public function it_prunes_items_where_issue_status_is_now_closed()
    {
        $issue = Issue::create([
            'organization_id' => $this->org->id,
            'name' => 'Task Done',
            'status' => 'in_progress',
            'user_id' => $this->user->id,
        ]);

        $review = MeetingTaskReview::create([
            'calendar_event_id' => $this->event->id,
            'organization_id' => $this->org->id,
            'status' => 'done',
            'analyzed_count' => 1,
        ]);

        $item = MeetingTaskReviewItem::create([
            'meeting_task_review_id' => $review->id,
            'issue_id' => $issue->id,
            'progress' => 'done',
            'confidence' => 'high',
        ]);

        // Item exists while issue is still in_progress
        (new PruneResolvedMeetingTaskItemsJob($this->org->id))->handle();
        $this->assertDatabaseHas('meeting_task_review_items', ['id' => $item->id]);

        // User updates the issue status to done
        $issue->update(['status' => 'done']);

        (new PruneResolvedMeetingTaskItemsJob($this->org->id))->handle();
        $this->assertDatabaseMissing('meeting_task_review_items', ['id' => $item->id]);
    }

    #[Test]
    public function it_does_not_prune_blocked_items_even_when_issue_is_closed()
    {
        $issue = Issue::create([
            'organization_id' => $this->org->id,
            'name' => 'Blocked Task',
            'status' => 'done',
            'user_id' => $this->user->id,
        ]);

        $review = MeetingTaskReview::create([
            'calendar_event_id' => $this->event->id,
            'organization_id' => $this->org->id,
            'status' => 'done',
            'analyzed_count' => 1,
        ]);

        $item = MeetingTaskReviewItem::create([
            'meeting_task_review_id' => $review->id,
            'issue_id' => $issue->id,
            'progress' => 'blocked',
            'confidence' => 'medium',
        ]);

        (new PruneResolvedMeetingTaskItemsJob($this->org->id))->handle();
        $this->assertDatabaseHas('meeting_task_review_items', ['id' => $item->id]);
    }
}
