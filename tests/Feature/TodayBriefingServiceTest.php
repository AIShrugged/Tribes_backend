<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingReview;
use App\Models\MeetingSummary;
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
