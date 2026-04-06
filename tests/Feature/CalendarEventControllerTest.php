<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarEventControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_owned_events_and_filters_by_day(): void
    {
        $user = User::factory()->create();
        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'source-1',
            'identity' => 'user@example.com',
        ]);

        $dayEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-1',
            'platform' => 'google_meet',
            'title' => 'Same Day Meeting',
            'url' => 'https://meet.google.com/same-day',
            'description' => 'Same day meeting',
            'starts_at' => '2026-04-06 10:00:00',
            'ends_at' => '2026-04-06 11:00:00',
            'required_bot' => false,
        ]);
        $dayEvent->sources()->attach($source->id, [
            'external_id' => 'event-1',
            'required_bot' => false,
        ]);

        $nextDayEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-2',
            'platform' => 'google_meet',
            'title' => 'Next Day Meeting',
            'url' => 'https://meet.google.com/next-day',
            'description' => 'Next day meeting',
            'starts_at' => '2026-04-07 10:00:00',
            'ends_at' => '2026-04-07 11:00:00',
            'required_bot' => false,
        ]);
        $nextDayEvent->sources()->attach($source->id, [
            'external_id' => 'event-2',
            'required_bot' => false,
        ]);

        Sanctum::actingAs($user);

        $allResponse = $this->getJson('/api/v1/calendar-events');

        $allResponse->assertOk();
        $this->assertSame('2', (string) $allResponse->headers->get('Items-Count'));

        $filteredResponse = $this->getJson('/api/v1/calendar-events?date=2026-04-06');

        $filteredResponse->assertOk();
        $this->assertSame('1', (string) $filteredResponse->headers->get('Items-Count'));
        $filteredResponse->assertJsonCount(1, 'data');
        $filteredResponse->assertJsonPath('data.0.id', $dayEvent->id);
        $filteredResponse->assertJsonPath('data.0.title', 'Same Day Meeting');
    }
}
