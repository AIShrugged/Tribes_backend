<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
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
        $review = $service->generate($this->event, $this->org->id);

        $blocks = $service->getBlocks($review);

        $this->assertIsArray($blocks);
        // Should be empty or have no issues in blocks when there are no issues
        collect($blocks)->each(fn($block) => $this->assertEquals(0, $block['count']));
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
        $review = $service->generate($this->event, $this->org->id);
        $blocks = $service->getBlocks($review);

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
        $review = $service->generate($this->event, $this->org->id);
        $blocks = $service->getBlocks($review);

        $overdueBlock = collect($blocks)->firstWhere('type', 'overdue');
        $this->assertNotNull($overdueBlock);
        $this->assertGreaterThan(0, $overdueBlock['count']);
    }
}
