<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Organization;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers manual bot connection scoped to an organization and the per-organization
 * calendar: the creator connects the bot from a specific organization, that org is
 * recorded on the calendar_event_source pivot, and the organization calendar lists
 * only meetings connected from that organization.
 */
class OrganizationBotCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $slug): Organization
    {
        return Organization::create(['name' => "Org {$slug}", 'slug' => $slug]);
    }

    private function member(Organization $organization, string $role = 'employee'): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => $role]);

        return $user;
    }

    private function sourceFor(User $user, string $suffix): Source
    {
        return Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => "recall-calendar-{$suffix}",
            'identity' => "{$suffix}@example.com",
        ]);
    }

    /**
     * A future meeting owned by $creator through $source. The pivot has no Recall
     * external id, so manually requiring the bot never reaches Recall over HTTP.
     */
    private function eventFor(User $creator, Source $source, string $suffix, array $pivot = []): CalendarEvent
    {
        $event = CalendarEvent::create([
            'source_id' => $source->id,
            'creator_user_id' => $creator->id,
            'platform' => 'google_meet',
            'title' => "Meeting {$suffix}",
            'url' => "https://meet.google.com/{$suffix}",
            'description' => '',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'required_bot' => false,
        ]);

        $event->sources()->attach($source->id, array_merge([
            'external_id' => null,
            'required_bot' => false,
        ], $pivot));

        return $event;
    }

    #[Test]
    public function require_records_the_connecting_organization_on_the_pivot(): void
    {
        Http::fake();

        $organization = $this->organization('a');
        $creator = $this->member($organization);
        $source = $this->sourceFor($creator, 'creator');
        $event = $this->eventFor($creator, $source, 'a');

        Sanctum::actingAs($creator);

        $this->postJson("/api/v1/calendar-events/{$event->id}/bot/require", [
            'required_bot' => true,
            'organization_id' => $organization->id,
        ])->assertOk();

        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $event->id,
            'source_id' => $source->id,
            'required_bot' => true,
            'organization_id' => $organization->id,
        ]);

        // No Recall external id is known yet, so nothing is scheduled with Recall.
        Http::assertNothingSent();
    }

    #[Test]
    public function require_rejects_an_organization_the_user_does_not_belong_to(): void
    {
        $orgA = $this->organization('a');
        $orgB = $this->organization('b');
        $creator = $this->member($orgA);
        $source = $this->sourceFor($creator, 'creator');
        $event = $this->eventFor($creator, $source, 'a');

        Sanctum::actingAs($creator);

        $this->postJson("/api/v1/calendar-events/{$event->id}/bot/require", [
            'required_bot' => true,
            'organization_id' => $orgB->id,
        ])->assertForbidden();

        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $event->id,
            'source_id' => $source->id,
            'required_bot' => false,
            'organization_id' => null,
        ]);
    }

    #[Test]
    public function require_needs_an_organization_when_enabling_the_bot(): void
    {
        $organization = $this->organization('a');
        $creator = $this->member($organization);
        $source = $this->sourceFor($creator, 'creator');
        $event = $this->eventFor($creator, $source, 'a');

        Sanctum::actingAs($creator);

        $this->postJson("/api/v1/calendar-events/{$event->id}/bot/require", [
            'required_bot' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('organization_id');
    }

    #[Test]
    public function disabling_the_bot_clears_the_recorded_organization(): void
    {
        Http::fake();

        $organization = $this->organization('a');
        $creator = $this->member($organization);
        $source = $this->sourceFor($creator, 'creator');
        $event = $this->eventFor($creator, $source, 'a', [
            'required_bot' => true,
            'organization_id' => null,
        ]);
        $event->sources()->updateExistingPivot($source->id, [
            'organization_id' => $organization->id,
        ]);

        Sanctum::actingAs($creator);

        $this->postJson("/api/v1/calendar-events/{$event->id}/bot/require", [
            'required_bot' => false,
        ])->assertOk();

        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $event->id,
            'source_id' => $source->id,
            'required_bot' => false,
            'organization_id' => null,
        ]);
    }

    #[Test]
    public function organization_calendar_lists_only_meetings_connected_from_that_organization(): void
    {
        $orgA = $this->organization('a');
        $orgB = $this->organization('b');
        $creator = $this->member($orgA);
        $orgB->users()->attach($creator, ['role' => 'employee']);
        $source = $this->sourceFor($creator, 'creator');

        $eventA = $this->eventFor($creator, $source, 'a', [
            'required_bot' => true,
            'organization_id' => $orgA->id,
        ]);
        $this->eventFor($creator, $source, 'b', [
            'required_bot' => true,
            'organization_id' => $orgB->id,
        ]);
        // Bot not connected: personal-only meeting, absent from every org calendar.
        $this->eventFor($creator, $source, 'n');

        Sanctum::actingAs($creator);

        $response = $this->getJson("/api/v1/calendar-events/organization?organization_id={$orgA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($eventA->id, $response->json('data.0.id'));
        $this->assertSame($orgA->id, $response->json('data.0.organization_id'));
    }

    #[Test]
    public function organization_calendar_requires_membership(): void
    {
        $orgA = $this->organization('a');
        $creator = $this->member($orgA);
        $source = $this->sourceFor($creator, 'creator');
        $this->eventFor($creator, $source, 'a', [
            'required_bot' => true,
            'organization_id' => $orgA->id,
        ]);

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->getJson("/api/v1/calendar-events/organization?organization_id={$orgA->id}")
            ->assertForbidden();
    }

    #[Test]
    public function organization_calendar_requires_an_organization_id(): void
    {
        $creator = $this->member($this->organization('a'));
        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/calendar-events/organization')
            ->assertStatus(422)
            ->assertJsonValidationErrors('organization_id');
    }

    #[Test]
    public function personal_calendar_exposes_the_organization_the_bot_is_connected_from(): void
    {
        $orgA = $this->organization('a');
        $creator = $this->member($orgA);
        $source = $this->sourceFor($creator, 'creator');

        $connected = $this->eventFor($creator, $source, 'a', [
            'required_bot' => true,
            'organization_id' => $orgA->id,
        ]);
        $unconnected = $this->eventFor($creator, $source, 'n');

        Sanctum::actingAs($creator);

        $data = collect($this->getJson('/api/v1/calendar-events')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame($orgA->id, $data[$connected->id]['organization_id']);
        $this->assertNull($data[$unconnected->id]['organization_id']);
    }
}
