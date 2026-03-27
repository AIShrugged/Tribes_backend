<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarEventCastingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function starts_at_and_ends_at_are_cast_to_datetime_on_reload(): void
    {
        $user = User::factory()->create();

        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'source-1',
            'identity' => 'user@example.com',
        ]);

        $event = CalendarEvent::create([
            'platform' => 'google_meet',
            'title' => 'Planning Sync',
            'url' => 'https://meet.google.com/test',
            'description' => 'Planning sync description',
            'starts_at' => '2026-03-26 18:00:00',
            'ends_at' => '2026-03-26 19:00:00',
        ]);

        $event->sources()->attach($source->id, [
            'external_id' => 'event-1',
            'required_bot' => false,
        ]);

        $reloaded = CalendarEvent::query()->findOrFail($event->id);

        $this->assertInstanceOf(Carbon::class, $reloaded->starts_at);
        $this->assertInstanceOf(Carbon::class, $reloaded->ends_at);
        $this->assertSame('2026-03-26 17:30:00', $reloaded->starts_at->subMinutes(30)->format('Y-m-d H:i:s'));
    }
}
