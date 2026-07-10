<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\MeetingSummary;
use App\Models\Organization;
use App\Models\TranscriptUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The second-brain "Протокол и агенда" tab must reach meetings the brain processes
 * at the ORGANIZATION level — e.g. manual transcript uploads and teammates' meetings,
 * which are org-visible but not "owned" by the viewer. CalendarEvent::viewableBy()
 * widens access for org managers; the /viewable picker + the summary/agenda reads use it.
 */
class CalendarEventViewableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function manager_sees_and_reads_an_org_visible_but_unowned_meeting(): void
    {
        [$manager, $org] = $this->managerFor();
        $meeting = $this->orgVisibleMeeting($org, $manager);
        MeetingSummary::create([
            'calendar_event_id' => $meeting->id,
            'status' => 'done',
            'title' => 'Org protocol',
            'summary' => '## summary',
            'key_points' => [],
            'decisions' => [],
        ]);

        Sanctum::actingAs($manager);

        // Picker (/viewable) surfaces the org-visible meeting…
        $this->getJson('/api/v1/calendar-events/viewable?limit=100')
            ->assertOk()
            ->assertJsonFragment(['id' => $meeting->id]);

        // …and the read endpoints resolve it (would be 404 under owned-only scoping).
        $this->getJson("/api/v1/calendar-events/{$meeting->id}/meeting-summary")
            ->assertOk()
            ->assertJsonPath('data.title', 'Org protocol');

        $this->getJson("/api/v1/calendar-events/{$meeting->id}/agendas")->assertOk();
    }

    #[Test]
    public function a_non_manager_org_member_cannot_reach_an_unowned_meeting(): void
    {
        [$manager, $org] = $this->managerFor();
        $member = User::factory()->create();
        $org->users()->attach($member->id, ['role' => 'employee']);
        $meeting = $this->orgVisibleMeeting($org, $manager);
        MeetingSummary::create([
            'calendar_event_id' => $meeting->id,
            'status' => 'done',
            'summary' => 'x',
            'key_points' => [],
            'decisions' => [],
        ]);

        Sanctum::actingAs($member);

        $this->getJson("/api/v1/calendar-events/{$meeting->id}/meeting-summary")->assertNotFound();
        $this->getJson('/api/v1/calendar-events/viewable?limit=100')
            ->assertOk()
            ->assertJsonMissing(['id' => $meeting->id]);
    }

    #[Test]
    public function owned_index_is_unchanged_and_excludes_org_visible_meetings(): void
    {
        [$manager, $org] = $this->managerFor();
        $meeting = $this->orgVisibleMeeting($org, $manager);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/calendar-events?limit=50')
            ->assertOk()
            ->assertJsonMissing(['id' => $meeting->id]);
    }

    /** Org-visible via a transcript upload, but NOT owned (no calendar source for the user). */
    private function orgVisibleMeeting(Organization $org, User $user): CalendarEvent
    {
        $event = CalendarEvent::create([
            'title' => 'Manual upload '.uniqid(),
            'platform' => 'test',
            'url' => 'https://meet.test/'.uniqid(),
            'description' => 'manually uploaded transcript',
            'starts_at' => now()->subHour(),
            'ends_at' => now(),
        ]);

        TranscriptUpload::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'calendar_event_id' => $event->id,
            'original_filename' => 'transcript.txt',
            'transcript_entries_count' => 1,
            'participants_count' => 1,
        ]);

        return $event;
    }

    /** @return array{0: User, 1: Organization} */
    private function managerFor(): array
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Org '.uniqid(), 'slug' => 'org-'.uniqid()]);
        $org->users()->attach($user->id, ['role' => 'manager']);

        return [$user, $org];
    }
}
