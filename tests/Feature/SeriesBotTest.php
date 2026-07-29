<?php

namespace Tests\Feature;

use App\Domain\DTO\EventDTO;
use App\Models\CalendarEvent;
use App\Models\Organization;
use App\Models\Source;
use App\Models\User;
use App\Services\Recall\CalendarEventSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers connecting the recording bot to a whole meeting series (recurring events
 * sharing one URL): the scope=series fan-out on POST bot/require, and inheritance
 * of the series' bot setting by newly-synced occurrences. Events carry no Recall
 * external id on the pivot, so scheduling never reaches Recall over HTTP.
 */
class SeriesBotTest extends TestCase
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

    private function hostSource(User $user, string $suffix): Source
    {
        return Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => "recall-calendar-{$suffix}",
            'identity' => "{$suffix}@example.com",
        ]);
    }

    /**
     * A meeting owned by $creator through $source, at $url. The pivot carries no
     * Recall external id, so requiring the bot never reaches Recall over HTTP.
     */
    private function seriesEvent(
        User $creator,
        Source $source,
        string $url,
        \DateTimeInterface $startsAt,
        array $pivot = [],
    ): CalendarEvent {
        $event = CalendarEvent::create([
            'source_id' => $source->id,
            'creator_user_id' => $creator->id,
            'platform' => 'google_meet',
            'title' => 'Weekly sync',
            'url' => $url,
            'description' => '',
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+1 hour'),
            'required_bot' => false,
        ]);

        $event->sources()->attach($source->id, array_merge([
            'external_id' => null,
            'required_bot' => false,
        ], $pivot));

        return $event;
    }

    #[Test]
    public function series_scope_enables_the_bot_on_every_future_occurrence(): void
    {
        Http::fake();

        $organization = $this->organization('a');
        $creator = $this->member($organization);
        $source = $this->hostSource($creator, 'creator');
        $url = 'https://meet.google.com/weekly-sync';

        $anchor = $this->seriesEvent($creator, $source, $url, now()->addDay());
        $next = $this->seriesEvent($creator, $source, $url, now()->addWeek());

        Sanctum::actingAs($creator);

        $this->postJson("/api/v1/calendar-events/{$anchor->id}/bot/require", [
            'required_bot' => true,
            'organization_id' => $organization->id,
            'scope' => 'series',
        ])->assertOk();

        foreach ([$anchor, $next] as $event) {
            $this->assertDatabaseHas('calendar_event_source', [
                'calendar_event_id' => $event->id,
                'source_id' => $source->id,
                'required_bot' => true,
                'organization_id' => $organization->id,
            ]);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function series_scope_ignores_past_occurrences_and_other_series(): void
    {
        Http::fake();

        $organization = $this->organization('a');
        $creator = $this->member($organization);
        $source = $this->hostSource($creator, 'creator');
        $url = 'https://meet.google.com/weekly-sync';

        $anchor = $this->seriesEvent($creator, $source, $url, now()->addDay());
        $past = $this->seriesEvent($creator, $source, $url, now()->subWeek());
        $otherSeries = $this->seriesEvent($creator, $source, 'https://meet.google.com/other', now()->addWeek());

        Sanctum::actingAs($creator);

        $this->postJson("/api/v1/calendar-events/{$anchor->id}/bot/require", [
            'required_bot' => true,
            'organization_id' => $organization->id,
            'scope' => 'series',
        ])->assertOk();

        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $anchor->id,
            'required_bot' => true,
            'organization_id' => $organization->id,
        ]);

        foreach ([$past, $otherSeries] as $event) {
            $this->assertDatabaseHas('calendar_event_source', [
                'calendar_event_id' => $event->id,
                'required_bot' => false,
                'organization_id' => null,
            ]);
        }
    }

    #[Test]
    public function series_scope_disables_the_bot_across_the_series(): void
    {
        Http::fake();

        $organization = $this->organization('a');
        $creator = $this->member($organization);
        $source = $this->hostSource($creator, 'creator');
        $url = 'https://meet.google.com/weekly-sync';

        $enabled = ['required_bot' => true, 'organization_id' => $organization->id];
        $anchor = $this->seriesEvent($creator, $source, $url, now()->addDay(), $enabled);
        $next = $this->seriesEvent($creator, $source, $url, now()->addWeek(), $enabled);

        Sanctum::actingAs($creator);

        $this->postJson("/api/v1/calendar-events/{$anchor->id}/bot/require", [
            'required_bot' => false,
            'scope' => 'series',
        ])->assertOk();

        foreach ([$anchor, $next] as $event) {
            $this->assertDatabaseHas('calendar_event_source', [
                'calendar_event_id' => $event->id,
                'required_bot' => false,
                'organization_id' => null,
            ]);
        }
    }

    #[Test]
    public function single_scope_touches_only_the_target_event(): void
    {
        Http::fake();

        $organization = $this->organization('a');
        $creator = $this->member($organization);
        $source = $this->hostSource($creator, 'creator');
        $url = 'https://meet.google.com/weekly-sync';

        $anchor = $this->seriesEvent($creator, $source, $url, now()->addDay());
        $next = $this->seriesEvent($creator, $source, $url, now()->addWeek());

        Sanctum::actingAs($creator);

        // Default scope (omitted) must remain single-event.
        $this->postJson("/api/v1/calendar-events/{$anchor->id}/bot/require", [
            'required_bot' => true,
            'organization_id' => $organization->id,
        ])->assertOk();

        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $anchor->id,
            'required_bot' => true,
        ]);
        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $next->id,
            'required_bot' => false,
            'organization_id' => null,
        ]);
    }

    #[Test]
    public function a_newly_synced_occurrence_inherits_the_series_bot_setting(): void
    {
        Http::fake();

        $organization = $this->organization('a');
        $creator = $this->member($organization);
        $source = $this->hostSource($creator, 'creator');
        $url = 'https://meet.google.com/weekly-sync';

        // Existing sibling of the series already has the bot enabled.
        $this->seriesEvent($creator, $source, $url, now()->addDay(), [
            'required_bot' => true,
            'organization_id' => $organization->id,
        ]);

        // A new occurrence arrives via sync (no Recall external id -> no HTTP).
        $dto = new EventDTO(
            externalId: null,
            platform: 'google_meet',
            startsAt: now()->addWeek()->toIso8601String(),
            endsAt: now()->addWeek()->addHour()->toIso8601String(),
            url: $url,
            title: 'Weekly sync',
            description: '',
            creatorEmail: null,
        );

        $synced = app(CalendarEventSyncService::class)->sync($source, $dto, []);

        $this->assertNotNull($synced);
        $this->assertDatabaseHas('calendar_event_source', [
            'calendar_event_id' => $synced->id,
            'source_id' => $source->id,
            'required_bot' => true,
            'organization_id' => $organization->id,
        ]);

        Http::assertNothingSent();
    }
}
