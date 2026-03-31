<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Followup;
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

class ParticipantControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function followup_owner_can_view_participants_for_non_owned_event(): void
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-organization',
        ]);

        $eventOwner = User::factory()->create();
        $followupOwner = User::factory()->create();

        $organization->users()->attach($eventOwner->id, ['role' => 'employee']);
        $organization->users()->attach($followupOwner->id, ['role' => 'employee']);

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
        $team->users()->attach($followupOwner->id);

        $source = Source::create([
            'user_id' => $eventOwner->id,
            'type' => 'google_calendar',
            'external_id' => 'source-1',
            'identity' => 'owner@example.com',
        ]);

        $calendarEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-1',
            'platform' => 'google_meet',
            'title' => 'Shared Meeting',
            'url' => 'https://meet.google.com/shared',
            'description' => 'Shared meeting',
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
            'required_bot' => false,
        ]);
        $calendarEvent->sources()->attach($source->id, [
            'external_id' => 'event-1',
            'required_bot' => false,
        ]);

        $participant = Participant::create([
            'calendar_event_id' => $calendarEvent->id,
            'name' => 'Alice',
        ]);

        Followup::create([
            'calendar_event_id' => $calendarEvent->id,
            'team_id' => $team->id,
            'user_id' => $followupOwner->id,
            'methodology_id' => $methodology->id,
            'status' => 'done',
            'text' => '{"summary":"ok"}',
        ]);

        Sanctum::actingAs($followupOwner);

        $response = $this->getJson("/api/v1/calendar-events/{$calendarEvent->id}/participants")
            ->assertOk();

        $response->assertJsonPath('data.0.id', $participant->id);
        $response->assertJsonPath('data.0.name', 'Alice');
    }
}
