<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingAgenda;
use App\Models\MeetingReview;
use App\Models\MeetingSummary;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarEventDetailControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_a_unified_detail_payload_with_key_takeaways(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-organization',
        ]);
        $organization->users()->attach($user->id, ['role' => 'employee']);

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
            'name' => 'Test Team',
            'slug' => 'test-team',
        ]);

        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'source-1',
            'identity' => 'owner@example.com',
        ]);

        $previousEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-prev',
            'platform' => 'google_meet',
            'title' => 'Wanda: Tech Sync',
            'url' => 'https://meet.google.com/wanda-tech-sync',
            'description' => 'Previous meeting',
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHour(),
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
            'key_points' => ['Previous key point'],
            'decisions' => ['Previous decision'],
        ]);

        $calendarEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-current',
            'platform' => 'google_meet',
            'title' => 'Wanda: Tech Sync',
            'url' => 'https://meet.google.com/wanda-tech-sync',
            'description' => 'Current meeting',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHour(),
            'required_bot' => false,
        ]);
        $calendarEvent->sources()->attach($source->id, [
            'external_id' => 'event-current',
            'required_bot' => false,
        ]);

        $participant = Participant::create([
            'calendar_event_id' => $calendarEvent->id,
            'name' => 'Ivan',
        ]);

        MeetingAgenda::create([
            'calendar_event_id' => $calendarEvent->id,
            'user_id' => $user->id,
            'type' => 'general',
            'status' => 'done',
            'content' => 'Discuss architecture and DoD.',
        ]);

        MeetingSummary::create([
            'calendar_event_id' => $calendarEvent->id,
            'status' => 'done',
            'title' => 'Wanda: Tech Sync #12',
            'summary' => 'Mono vs multi-agent architecture debate.',
            'key_points' => ['Current setup: 2 agents'],
            'decisions' => ['Move toward orchestrator pattern with role-based agents'],
        ]);

        MeetingReview::create([
            'calendar_event_id' => $calendarEvent->id,
            'status' => 'done',
            'score' => 7.5,
            'score_breakdown' => ['goal_clarity' => 8],
            'key_insight' => 'The team needs clearer role separation.',
            'suggestions' => ['Write a design doc for the orchestrator pattern'],
            'agenda_analysis' => ['had_clear_agenda' => true],
            'participation' => [['name' => 'Ivan', 'assessment' => 'Chair']],
        ]);

        Issue::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'team_id' => $team->id,
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $calendarEvent->id,
            'name' => 'Design multi-agent architecture with orchestrator pattern',
            'description' => 'New from this meeting — needs architecture doc',
            'assignee_name' => 'All',
            'status' => 'open',
            'type' => 'organization',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/calendar-events/{$calendarEvent->id}/detail");

        $response->assertOk()
            ->assertJsonPath('data.event.id', $calendarEvent->id)
            ->assertJsonPath('data.event.title', 'Wanda: Tech Sync')
            ->assertJsonPath('data.event.meeting_link.url', 'https://meet.google.com/wanda-tech-sync')
            ->assertJsonPath('data.summary.title', 'Wanda: Tech Sync #12')
            ->assertJsonPath('data.review.key_insight', 'The team needs clearer role separation.')
            ->assertJsonPath('data.previous_meeting.id', $previousEvent->id)
            ->assertJsonPath('data.participants.0.name', 'Ivan')
            ->assertJsonPath('data.agendas.0.type', 'general')
            ->assertJsonPath('data.key_takeaways.0.read_more.section', 'meeting_summary')
            ->assertJsonMissingPath('data.followup')
            ->assertJsonMissingPath('data.counts');
    }
}
