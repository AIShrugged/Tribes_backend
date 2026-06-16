<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\MeetingAgenda;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\User;
use App\Services\Agenda\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgendaGenerateMissingCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_backfills_agendas_for_all_calendar_events(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-organization',
        ]);
        $organization->users()->attach($user->id, ['role' => 'employee']);

        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'source-1',
            'identity' => 'owner@example.com',
        ]);

        $eventWithAgenda = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-with-agenda',
            'platform' => 'google_meet',
            'title' => 'Has agenda',
            'url' => 'https://meet.google.com/has-agenda',
            'description' => '',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'required_bot' => false,
        ]);
        MeetingAgenda::create([
            'calendar_event_id' => $eventWithAgenda->id,
            'type' => 'general',
            'status' => 'done',
            'content' => 'Existing agenda',
        ]);

        $missingEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-missing-agenda',
            'platform' => 'google_meet',
            'title' => 'Missing agenda',
            'url' => 'https://meet.google.com/missing-agenda',
            'description' => '',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
            'required_bot' => false,
        ]);

        $service = Mockery::mock(AgendaService::class);
        $service->shouldReceive('generateForEvent')
            ->once()
            ->with(Mockery::on(fn (CalendarEvent $event) => $event->is($eventWithAgenda)))
            ->ordered();
        $service->shouldReceive('generateForEvent')
            ->once()
            ->with(Mockery::on(fn (CalendarEvent $event) => $event->is($missingEvent)))
            ->ordered();

        $this->app->instance(AgendaService::class, $service);

        $this->artisan('agenda:generate-missing')
            ->expectsOutput('Processed 2 event(s) for agenda backfill.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_backfill_only_events_for_a_given_date(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-organization',
        ]);
        $organization->users()->attach($user->id, ['role' => 'employee']);

        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'source-1',
            'identity' => 'owner@example.com',
        ]);

        $fromDate = now()->addDays(3)->toDateString();

        $eventBeforeFromDate = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-before-from-date',
            'platform' => 'google_meet',
            'title' => 'Before from date meeting',
            'url' => 'https://meet.google.com/before-from-date',
            'description' => '',
            'starts_at' => now()->addDays(2)->setHour(10)->setMinute(0),
            'ends_at' => now()->addDays(2)->setHour(11)->setMinute(0),
            'required_bot' => false,
        ]);

        $eventOnTargetDate = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-target-date',
            'platform' => 'google_meet',
            'title' => 'Target date meeting',
            'url' => 'https://meet.google.com/target-date',
            'description' => '',
            'starts_at' => now()->addDays(3)->setHour(10)->setMinute(0),
            'ends_at' => now()->addDays(3)->setHour(11)->setMinute(0),
            'required_bot' => false,
        ]);

        $eventOnOtherDate = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-other-date',
            'platform' => 'google_meet',
            'title' => 'Other date meeting',
            'url' => 'https://meet.google.com/other-date',
            'description' => '',
            'starts_at' => now()->addDays(4)->setHour(10)->setMinute(0),
            'ends_at' => now()->addDays(4)->setHour(11)->setMinute(0),
            'required_bot' => false,
        ]);

        $service = Mockery::mock(AgendaService::class);
        $service->shouldReceive('generateForEvent')
            ->once()
            ->with(Mockery::on(fn (CalendarEvent $event) => $event->is($eventOnTargetDate)))
            ->ordered();
        $service->shouldReceive('generateForEvent')
            ->once()
            ->with(Mockery::on(fn (CalendarEvent $event) => $event->is($eventOnOtherDate)))
            ->ordered();
        $service->shouldNotReceive('generateForEvent')
            ->with(Mockery::on(fn (CalendarEvent $event) => $event->is($eventBeforeFromDate)));

        $this->app->instance(AgendaService::class, $service);

        $this->artisan('agenda:generate-missing', ['--from' => $fromDate])
            ->expectsOutput("Processed 2 event(s) for agenda backfill from {$fromDate}.")
            ->assertExitCode(0);
    }
}
