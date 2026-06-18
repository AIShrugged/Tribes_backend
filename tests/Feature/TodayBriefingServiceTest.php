<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\MeetingReview;
use App\Models\MeetingSummary;
use App\Models\MeetingTaskReview;
use App\Models\MeetingTaskReviewItem;
use App\Models\Organization;
use App\Models\Source;
use App\Models\User;
use App\Services\Today\TodayBriefingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TodayBriefingServiceTest extends TestCase
{
    use RefreshDatabase;

    private TodayBriefingService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TodayBriefingService::class);
        $this->user = User::factory()->create();
    }

    private function createSource(): Source
    {
        return Source::create([
            'user_id' => $this->user->id,
            'external_id' => 'test_ext_' . uniqid(),
            'identity' => 'test_' . uniqid() . '@test.com',
            'type' => 'google_calendar',
        ]);
    }

    private function createEvent(array $attrs = []): CalendarEvent
    {
        $event = CalendarEvent::create(array_merge([
            'title' => 'Test Meeting',
            'url' => 'https://meet.google.com/test-' . uniqid(),
            'starts_at' => Carbon::today()->setHour(10),
            'ends_at' => Carbon::today()->setHour(11),
            'platform' => 'google_meet',
            'description' => '',
        ], $attrs));

        $source = $this->createSource();
        $event->sources()->attach($source);

        return $event;
    }

    #[Test]
    public function it_returns_empty_state_when_no_calendar_connected(): void
    {
        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertEquals('empty', $briefing->state);
        $this->assertEmpty($briefing->events);
        $this->assertNull($briefing->nudge);
    }

    #[Test]
    public function it_returns_empty_state_when_no_events_for_date(): void
    {
        $this->createSource();

        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertEquals('empty', $briefing->state);
        $this->assertEmpty($briefing->events);
    }

    #[Test]
    public function it_returns_waiting_state_when_events_have_no_summary(): void
    {
        $this->createEvent();

        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertEquals('waiting', $briefing->state);
        $this->assertCount(1, $briefing->events);
        $this->assertEquals('Test Meeting', $briefing->events[0]->title);
    }

    #[Test]
    public function it_returns_active_state_when_event_has_done_summary(): void
    {
        $event = $this->createEvent();
        MeetingSummary::create([
            'calendar_event_id' => $event->id,
            'status' => 'done',
            'title' => 'Summary Title',
            'summary' => 'Summary text',
            'key_points' => ['point 1'],
            'decisions' => [],
        ]);

        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertEquals('active', $briefing->state);
        $this->assertEquals('ready', $briefing->events[0]->meeting_state);
        $this->assertNotNull($briefing->events[0]->summary);
        $this->assertEquals('Summary text', $briefing->events[0]->summary->summary);
    }

    #[Test]
    public function it_includes_review_when_present(): void
    {
        $event = $this->createEvent();
        MeetingSummary::create([
            'calendar_event_id' => $event->id,
            'status' => 'done',
            'title' => 'Test',
            'summary' => 'Test',
        ]);
        MeetingReview::create([
            'calendar_event_id' => $event->id,
            'status' => 'done',
            'key_insight' => 'Key insight text',
            'suggestions' => ['suggestion 1'],
        ]);

        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertNotNull($briefing->events[0]->review);
        $this->assertEquals('Key insight text', $briefing->events[0]->review->key_insight);
    }

    #[Test]
    public function it_excludes_done_tasks_from_event(): void
    {
        $event = $this->createEvent();
        MeetingSummary::create([
            'calendar_event_id' => $event->id,
            'status' => 'done',
        ]);

        Issue::create([
            'name' => 'Open task',
            'status' => 'open',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event->id,
            'user_id' => $this->user->id,
        ]);
        Issue::create([
            'name' => 'Done task',
            'status' => 'done',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event->id,
            'user_id' => $this->user->id,
        ]);

        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertCount(1, $briefing->events[0]->tasks);
        $this->assertEquals('Open task', $briefing->events[0]->tasks[0]->name);
    }

    #[Test]
    public function it_separates_new_action_items_from_updated_tasks(): void
    {
        $event = $this->createEvent();
        MeetingSummary::create([
            'calendar_event_id' => $event->id,
            'status' => 'done',
        ]);

        // New task created on this meeting → belongs in `tasks`.
        Issue::create([
            'name' => 'New action item',
            'status' => 'open',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event->id,
            'user_id' => $this->user->id,
        ]);

        // Pre-existing issue (sourced elsewhere) augmented by THIS meeting via a merge comment
        // whose author is NON-null → belongs in `updated_tasks`, not `tasks`.
        $preExisting = Issue::create([
            'name' => 'Carryover augmented',
            'status' => 'in_progress',
            'sourceable_type' => null,
            'sourceable_id' => null,
            'user_id' => $this->user->id,
        ]);
        IssueComment::create([
            'issue_id' => $preExisting->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'calendar_event_id' => $event->id,
            'content' => '**Обновление по встрече:** сдвинули срок.',
        ]);

        $briefing = $this->service->getBriefing($this->user, Carbon::today());
        $eventDTO = $briefing->events[0];

        $this->assertEqualsCanonicalizing(
            ['New action item'],
            collect($eventDTO->tasks)->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['Carryover augmented'],
            collect($eventDTO->updated_tasks)->pluck('name')->all(),
        );
        // Updated tasks stay out of the readiness totals.
        $this->assertEquals(1, $eventDTO->total_tasks_count);
    }

    #[Test]
    public function it_returns_empty_updated_tasks_when_no_merge_comments(): void
    {
        $event = $this->createEvent();
        MeetingSummary::create([
            'calendar_event_id' => $event->id,
            'status' => 'done',
        ]);
        Issue::create([
            'name' => 'New action item',
            'status' => 'open',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event->id,
            'user_id' => $this->user->id,
        ]);

        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertEmpty($briefing->events[0]->updated_tasks);
    }

    #[Test]
    public function it_excludes_cancelled_issues_from_updated_tasks(): void
    {
        $event = $this->createEvent();
        MeetingSummary::create(['calendar_event_id' => $event->id, 'status' => 'done']);

        $cancelled = Issue::create([
            'name' => 'Cancelled carryover',
            'status' => 'cancelled',
            'sourceable_type' => null,
            'sourceable_id' => null,
            'user_id' => $this->user->id,
        ]);
        IssueComment::create([
            'issue_id' => $cancelled->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'calendar_event_id' => $event->id,
            'content' => '**Обновление по встрече:** уже неактуально.',
        ]);

        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertEmpty($briefing->events[0]->updated_tasks);
    }

    #[Test]
    public function it_carries_updated_tasks_from_previous_meeting_to_scheduled_meeting(): void
    {
        $url = 'https://meet.google.com/series-' . uniqid();

        // Previous meeting in the series (already happened, earlier in time).
        $prev = $this->createEvent([
            'url' => $url,
            'starts_at' => Carbon::today()->setHour(10),
            'ends_at' => Carbon::today()->setHour(11),
        ]);

        // Task created on the previous meeting → carried into `tasks`.
        Issue::create([
            'name' => 'Created on prev meeting',
            'status' => 'open',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $prev->id,
            'user_id' => $this->user->id,
        ]);

        // Pre-existing issue the previous meeting augmented via a merge comment → `updated_tasks`.
        $updated = Issue::create([
            'name' => 'Updated on prev meeting',
            'status' => 'in_progress',
            'sourceable_type' => null,
            'sourceable_id' => null,
            'user_id' => $this->user->id,
        ]);
        IssueComment::create([
            'issue_id' => $updated->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'calendar_event_id' => $prev->id,
            'content' => '**Обновление по встрече:** сдвинули срок.',
        ]);

        // Scheduled (future) meeting in the same series — the one we query.
        $this->createEvent([
            'url' => $url,
            'starts_at' => Carbon::tomorrow()->setHour(10),
            'ends_at' => Carbon::tomorrow()->setHour(11),
        ]);

        $briefing = $this->service->getBriefing($this->user, Carbon::tomorrow());
        $eventDTO = $briefing->events[0];

        $this->assertEquals('scheduled', $eventDTO->meeting_state);
        $this->assertEqualsCanonicalizing(
            ['Created on prev meeting'],
            collect($eventDTO->tasks)->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['Updated on prev meeting'],
            collect($eventDTO->updated_tasks)->pluck('name')->all(),
        );
        // Update context (the merge comment) resolves against the PREVIOUS meeting's id.
        $this->assertEquals(
            '**Обновление по встрече:** сдвинули срок.',
            $eventDTO->updated_tasks[0]->context,
        );
        // Carried updated task stays out of the readiness totals.
        $this->assertEquals(1, $eventDTO->total_tasks_count);
    }

    #[Test]
    public function it_classifies_issue_that_is_both_new_and_commented_as_new_only(): void
    {
        // De-dup guard: an issue sourced from THIS meeting that also got a merge comment for
        // it must appear only in `tasks` (new), never in `updated_tasks`.
        $event = $this->createEvent();
        MeetingSummary::create(['calendar_event_id' => $event->id, 'status' => 'done']);

        $issue = Issue::create([
            'name' => 'New and commented',
            'status' => 'open',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event->id,
            'user_id' => $this->user->id,
        ]);
        IssueComment::create([
            'issue_id' => $issue->id,
            'user_id' => $this->user->id,
            'parent_id' => null,
            'calendar_event_id' => $event->id,
            'content' => '**Обновление по встрече:** уточнили формулировку.',
        ]);

        $briefing = $this->service->getBriefing($this->user, Carbon::today());
        $eventDTO = $briefing->events[0];

        $this->assertEqualsCanonicalizing(['New and commented'], collect($eventDTO->tasks)->pluck('name')->all());
        $this->assertEmpty($eventDTO->updated_tasks);
    }

    #[Test]
    public function it_scopes_updated_tasks_to_the_requested_organization(): void
    {
        $orgA = Organization::create(['name' => 'Org A', 'slug' => 'org-a']);
        $orgB = Organization::create(['name' => 'Org B', 'slug' => 'org-b']);

        // Event must be reachable for orgA: getBriefing scopes events by source.organization_id.
        $source = Source::create([
            'user_id' => $this->user->id,
            'external_id' => 'orga_src_' . uniqid(),
            'identity' => 'orga_' . uniqid() . '@test.com',
            'type' => 'google_calendar',
            'organization_id' => $orgA->id,
        ]);
        $event = CalendarEvent::create([
            'title' => 'Org A Meeting',
            'url' => 'https://meet.google.com/orga-' . uniqid(),
            'starts_at' => Carbon::today()->setHour(10),
            'ends_at' => Carbon::today()->setHour(11),
            'platform' => 'google_meet',
            'description' => '',
        ]);
        $event->sources()->attach($source);
        MeetingSummary::create(['calendar_event_id' => $event->id, 'status' => 'done']);

        foreach ([['Org A task', $orgA->id], ['Org B task', $orgB->id]] as [$name, $orgId]) {
            $issue = Issue::create([
                'name' => $name,
                'status' => 'open',
                'organization_id' => $orgId,
                'sourceable_type' => null,
                'sourceable_id' => null,
                'user_id' => $this->user->id,
            ]);
            IssueComment::create([
                'issue_id' => $issue->id,
                'user_id' => $this->user->id,
                'parent_id' => null,
                'calendar_event_id' => $event->id,
                'content' => '**Обновление по встрече.**',
            ]);
        }

        $briefing = $this->service->getBriefing($this->user, Carbon::today(), $orgA->id);

        $this->assertEqualsCanonicalizing(
            ['Org A task'],
            collect($briefing->events[0]->updated_tasks)->pluck('name')->all(),
        );
    }

    #[Test]
    public function it_surfaces_done_tasks_from_meeting_task_review(): void
    {
        // "What was done": pre-existing tasks the meeting task review flagged as completed
        // (progress='done') surface in done_tasks regardless of the task's current status,
        // with the transcript quote (notes) as context. Other progress values are excluded.
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $event = $this->createEvent();
        MeetingSummary::create(['calendar_event_id' => $event->id, 'status' => 'done']);

        $completed = Issue::create([
            'name' => 'Shipped the API',
            'status' => 'in_progress',
            'sourceable_type' => null,
            'sourceable_id' => null,
            'user_id' => $this->user->id,
        ]);
        $stillGoing = Issue::create([
            'name' => 'Refactor pipeline',
            'status' => 'in_progress',
            'sourceable_type' => null,
            'sourceable_id' => null,
            'user_id' => $this->user->id,
        ]);

        $review = MeetingTaskReview::create([
            'calendar_event_id' => $event->id,
            'organization_id' => $org->id,
            'status' => 'done',
            'analyzed_count' => 2,
        ]);
        MeetingTaskReviewItem::create([
            'meeting_task_review_id' => $review->id,
            'issue_id' => $completed->id,
            'progress' => 'done',
            'confidence' => 'high',
            'notes' => 'Деплой выкатили в прод вчера.',
        ]);
        MeetingTaskReviewItem::create([
            'meeting_task_review_id' => $review->id,
            'issue_id' => $stillGoing->id,
            'progress' => 'in_progress',
            'confidence' => 'medium',
            'notes' => 'Ещё в работе.',
        ]);

        $eventDTO = $this->service->getBriefing($this->user, Carbon::today())->events[0];

        $this->assertEqualsCanonicalizing(
            ['Shipped the API'],
            collect($eventDTO->done_tasks)->pluck('name')->all(),
        );
        $this->assertEquals('Деплой выкатили в прод вчера.', $eventDTO->done_tasks[0]->context);
    }

    #[Test]
    public function it_returns_empty_done_tasks_when_no_task_review(): void
    {
        $event = $this->createEvent();
        MeetingSummary::create(['calendar_event_id' => $event->id, 'status' => 'done']);

        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertEmpty($briefing->events[0]->done_tasks);
    }

    #[Test]
    public function it_builds_waiting_on_you_tasks(): void
    {
        // Need an event today so state != empty
        $event = $this->createEvent();
        MeetingSummary::create([
            'calendar_event_id' => $event->id,
            'status' => 'done',
        ]);

        Issue::create([
            'name' => 'My task',
            'status' => 'open',
            'assignee_id' => $this->user->id,
            'user_id' => $this->user->id,
        ]);
        Issue::create([
            'name' => 'Done task',
            'status' => 'done',
            'assignee_id' => $this->user->id,
            'user_id' => $this->user->id,
        ]);

        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertCount(1, $briefing->waiting_on_you);
        $this->assertEquals('My task', $briefing->waiting_on_you[0]->name);
    }

    #[Test]
    public function it_returns_correct_date(): void
    {
        $this->createSource();
        $date = Carbon::parse('2026-04-15');

        $briefing = $this->service->getBriefing($this->user, $date);

        $this->assertEquals('2026-04-15', $briefing->date);
    }

    #[Test]
    public function it_collects_carried_tasks_from_series(): void
    {
        $url = 'https://meet.google.com/series-' . uniqid();

        // Old event with open task
        $oldEvent = $this->createEvent([
            'title' => 'Weekly Sync',
            'url' => $url,
            'starts_at' => Carbon::today()->subDays(7)->setHour(10),
            'ends_at' => Carbon::today()->subDays(7)->setHour(11),
        ]);
        Issue::create([
            'name' => 'Carried task',
            'status' => 'open',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $oldEvent->id,
            'user_id' => $this->user->id,
        ]);

        // Today's event in same series
        $todayEvent = $this->createEvent([
            'title' => 'Weekly Sync',
            'url' => $url,
            'starts_at' => Carbon::today()->setHour(10),
            'ends_at' => Carbon::today()->setHour(11),
        ]);
        MeetingSummary::create([
            'calendar_event_id' => $todayEvent->id,
            'status' => 'done',
        ]);

        $briefing = $this->service->getBriefing($this->user, Carbon::today());

        $this->assertNotEmpty($briefing->carried_tasks);
        $this->assertEquals('Carried task', $briefing->carried_tasks[0]->name);
    }
}
