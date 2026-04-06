<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingReview;
use App\Models\MeetingSummary;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeamDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_a_team_scoped_dashboard(): void
    {
        Carbon::setTestNow('2026-04-06 12:00:00');

        $organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-organization',
        ]);

        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Wanda Team',
            'slug' => 'wanda-team',
        ]);

        $viewer = User::factory()->create();
        $owner = User::factory()->create();
        $organization->users()->attach($viewer->id, ['role' => 'employee']);
        $organization->users()->attach($owner->id, ['role' => 'employee']);
        $team->users()->attach($viewer->id);
        $team->users()->attach($owner->id);

        $source = Source::create([
            'user_id' => $owner->id,
            'type' => 'google_calendar',
            'external_id' => 'source-1',
            'identity' => 'owner@example.com',
        ]);

        $previousEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-prev',
            'platform' => 'google_meet',
            'title' => 'Wanda: Tech Sync',
            'url' => 'https://meet.google.com/wanda-prev',
            'description' => 'Previous meeting',
            'starts_at' => '2026-03-30 10:00:00',
            'ends_at' => '2026-03-30 11:00:00',
            'required_bot' => false,
        ]);
        $previousEvent->sources()->attach($source->id, [
            'external_id' => 'event-prev',
            'required_bot' => false,
        ]);
        MeetingSummary::create([
            'calendar_event_id' => $previousEvent->id,
            'status' => 'done',
            'title' => 'Wanda: Tech Sync #11',
            'summary' => 'Previous meeting recap.',
            'key_points' => ['Previous point'],
            'decisions' => ['Decide on orchestrator pattern'],
        ]);

        $currentMeeting = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-current',
            'platform' => 'google_meet',
            'title' => 'Wanda: Tech Sync',
            'url' => 'https://meet.google.com/wanda-current',
            'description' => 'Current meeting',
            'starts_at' => '2026-04-06 10:00:00',
            'ends_at' => '2026-04-06 11:00:00',
            'required_bot' => false,
        ]);
        $currentMeeting->sources()->attach($source->id, [
            'external_id' => 'event-current',
            'required_bot' => false,
        ]);
        Participant::create([
            'calendar_event_id' => $currentMeeting->id,
            'name' => 'Ivan',
        ]);
        MeetingSummary::create([
            'calendar_event_id' => $currentMeeting->id,
            'status' => 'done',
            'title' => 'Wanda: Tech Sync #12',
            'summary' => 'Current meeting recap.',
            'key_points' => ['Current setup: 2 agents'],
            'decisions' => ['Move toward orchestrator pattern with role-based agents'],
        ]);
        MeetingReview::create([
            'calendar_event_id' => $currentMeeting->id,
            'status' => 'done',
            'score' => 7.5,
            'score_breakdown' => ['goal_clarity' => 8],
            'key_insight' => 'The team needs clearer role separation.',
            'suggestions' => ['Write a design doc'],
            'agenda_analysis' => ['had_clear_agenda' => true],
            'participation' => [['name' => 'Ivan', 'assessment' => 'Chair']],
        ]);

        $upcomingEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-upcoming',
            'platform' => 'google_meet',
            'title' => 'Wanda: Tech Sync',
            'url' => 'https://meet.google.com/wanda-upcoming',
            'description' => 'Upcoming meeting',
            'starts_at' => '2026-04-07 10:00:00',
            'ends_at' => '2026-04-07 11:00:00',
            'required_bot' => false,
        ]);
        $upcomingEvent->sources()->attach($source->id, [
            'external_id' => 'event-upcoming',
            'required_bot' => false,
        ]);
        Participant::create([
            'calendar_event_id' => $upcomingEvent->id,
            'name' => 'Boris',
        ]);

        $overdueIssue = Issue::create([
            'user_id' => $viewer->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $currentMeeting->id,
            'name' => 'New dashboard design',
            'description' => 'Needs delivery',
            'assignee_id' => $viewer->id,
            'assignee_name' => 'Slava',
            'due_date' => '2026-04-01',
            'status' => 'open',
            'type' => 'organization',
        ]);

        $inProgressIssue = Issue::create([
            'user_id' => $viewer->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $previousEvent->id,
            'name' => 'Agent false completion fix',
            'description' => 'In progress',
            'assignee_id' => $owner->id,
            'assignee_name' => 'Kostya',
            'due_date' => '2026-04-10',
            'status' => 'in_progress',
            'type' => 'organization',
        ]);

        $doneIssue = Issue::create([
            'user_id' => $viewer->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $previousEvent->id,
            'name' => 'Calendar integration fix',
            'description' => 'Completed',
            'assignee_id' => $owner->id,
            'assignee_name' => 'Kostya',
            'due_date' => '2026-04-03',
            'status' => 'done',
            'type' => 'organization',
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson("/api/v1/teams/{$team->id}/dashboard");

        $response->assertOk()
            ->assertJsonPath('data.kpis.action_items.total', 3)
            ->assertJsonPath('data.kpis.action_items.overdue', 1)
            ->assertJsonPath('data.tabs.meeting_readiness.status', 'attention')
            ->assertJsonPath('data.tabs.meeting_readiness.meeting.id', $upcomingEvent->id)
            ->assertJsonPath('data.tabs.status.sections.0.count', 1)
            ->assertJsonPath('data.sections.decisions_needed.0.title', 'Move toward orchestrator pattern with role-based agents')
            ->assertJsonPath('data.sections.deadlines_and_priorities.0.id', $overdueIssue->id);

        $response->assertJsonMissingPath('data.day');
        $response->assertJsonMissingPath('data.selected_meeting');

        Carbon::setTestNow();
    }
}
