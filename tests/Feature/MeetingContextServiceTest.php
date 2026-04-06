<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingSummary;
use App\Models\User;
use App\Services\Meeting\MeetingContextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingContextServiceTest extends TestCase
{
    use RefreshDatabase;

    private MeetingContextService $service;
    private User $user;
    private string $seriesUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MeetingContextService::class);
        $this->user = User::factory()->create();
        $this->seriesUrl = 'https://meet.google.com/series-' . uniqid();
    }

    private function createSeriesEvent(Carbon $date): CalendarEvent
    {
        return CalendarEvent::create([
            'title' => 'Weekly Sync',
            'url' => $this->seriesUrl,
            'starts_at' => $date->copy()->setHour(10),
            'ends_at' => $date->copy()->setHour(11),
            'platform' => 'google_meet',
            'description' => '',
        ]);
    }

    #[Test]
    public function it_finds_previous_events_in_series(): void
    {
        $event1 = $this->createSeriesEvent(Carbon::today()->subDays(14));
        $event2 = $this->createSeriesEvent(Carbon::today()->subDays(7));
        $current = $this->createSeriesEvent(Carbon::today());

        $previous = $this->service->findPreviousEvents($current);

        $this->assertCount(2, $previous);
        $this->assertEquals($event2->id, $previous[0]->id);
        $this->assertEquals($event1->id, $previous[1]->id);
    }

    #[Test]
    public function it_finds_previous_event_with_summary(): void
    {
        $event1 = $this->createSeriesEvent(Carbon::today()->subDays(14));
        MeetingSummary::create([
            'calendar_event_id' => $event1->id,
            'status' => 'done',
            'title' => 'Old summary',
        ]);

        $event2 = $this->createSeriesEvent(Carbon::today()->subDays(7));
        // event2 has no summary

        $current = $this->createSeriesEvent(Carbon::today());

        $found = $this->service->findPreviousEventWithSummary($current);

        $this->assertNotNull($found);
        $this->assertEquals($event1->id, $found->id);
    }

    #[Test]
    public function it_gets_carried_tasks(): void
    {
        $old = $this->createSeriesEvent(Carbon::today()->subDays(7));
        Issue::create([
            'name' => 'Open task',
            'status' => 'open',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $old->id,
            'user_id' => $this->user->id,
        ]);
        Issue::create([
            'name' => 'Done task',
            'status' => 'done',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $old->id,
            'user_id' => $this->user->id,
        ]);

        $current = $this->createSeriesEvent(Carbon::today());

        $carried = $this->service->getCarriedTasks($current);

        $this->assertCount(1, $carried);
        $this->assertEquals('Open task', $carried->first()->name);
    }

    #[Test]
    public function it_returns_empty_carried_when_no_previous_events(): void
    {
        $event = CalendarEvent::create([
            'title' => 'Unique Meeting',
            'url' => 'https://meet.google.com/unique-' . uniqid(),
            'starts_at' => Carbon::today()->setHour(10),
            'ends_at' => Carbon::today()->setHour(11),
            'description' => '',
            'platform' => 'google_meet',
        ]);

        $carried = $this->service->getCarriedTasks($event);

        $this->assertCount(0, $carried);
    }

    #[Test]
    public function it_counts_syncs_since_created(): void
    {
        $event1 = $this->createSeriesEvent(Carbon::today()->subDays(21));
        $this->createSeriesEvent(Carbon::today()->subDays(14));
        $this->createSeriesEvent(Carbon::today()->subDays(7));
        $this->createSeriesEvent(Carbon::today());

        // registration_date is after event1 starts (task created during/after meeting)
        $issue = Issue::create([
            'name' => 'Task from event1',
            'status' => 'open',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event1->id,
            'user_id' => $this->user->id,
            'registration_date' => Carbon::today()->subDays(21)->setHour(11),
        ]);

        $count = $this->service->countSyncsSinceCreated($issue);

        // 3 events after registration: -14d, -7d, today
        $this->assertEquals(3, $count);
    }

    #[Test]
    public function it_batch_counts_syncs(): void
    {
        $event1 = $this->createSeriesEvent(Carbon::today()->subDays(14));
        $event2 = $this->createSeriesEvent(Carbon::today()->subDays(7));
        $this->createSeriesEvent(Carbon::today());

        $issue1 = Issue::create([
            'name' => 'Task 1',
            'status' => 'open',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event1->id,
            'user_id' => $this->user->id,
            'registration_date' => Carbon::today()->subDays(14)->setHour(11),
        ]);
        $issue2 = Issue::create([
            'name' => 'Task 2',
            'status' => 'open',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event2->id,
            'user_id' => $this->user->id,
            'registration_date' => Carbon::today()->subDays(7)->setHour(11),
        ]);

        $counts = $this->service->batchCountSyncsSinceCreated(collect([$issue1, $issue2]));

        $this->assertEquals(2, $counts[$issue1->id]);
        $this->assertEquals(1, $counts[$issue2->id]);
    }

    #[Test]
    public function it_gets_completed_tasks_between_events(): void
    {
        $event1 = $this->createSeriesEvent(Carbon::today()->subDays(7));
        Issue::create([
            'name' => 'Completed between',
            'status' => 'done',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event1->id,
            'user_id' => $this->user->id,
            'updated_at' => Carbon::today()->subDays(3),
        ]);
        Issue::create([
            'name' => 'Completed before',
            'status' => 'done',
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $event1->id,
            'user_id' => $this->user->id,
            'updated_at' => Carbon::today()->subDays(10),
        ]);

        $current = $this->createSeriesEvent(Carbon::today());

        $completed = $this->service->getCompletedTasksBetween($current, $event1);

        $this->assertCount(1, $completed);
        $this->assertEquals('Completed between', $completed->first()->name);
    }
}
