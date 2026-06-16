<?php

namespace Tests\Feature;

use App\Enums\AgendaStatus;
use App\Jobs\GenerateAgendaJob;
use App\Models\CalendarEvent;
use App\Models\MeetingAgenda;
use App\Models\MeetingSummary;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use App\Services\Agenda\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgendaGenerationRetryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function generate_endpoint_allows_retry_after_failed_agenda(): void
    {
        [$user, $event] = $this->makeEventWithTeam();

        MeetingAgenda::create([
            'calendar_event_id' => $event->id,
            'user_id' => null,
            'type' => 'general',
            'status' => AgendaStatus::FAILED,
            'raw_json' => ['old' => 'payload'],
            'content' => 'old content',
            'send_scheduled_at' => now()->subMinutes(30),
        ]);

        Queue::fake();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/calendar-events/{$event->id}/agendas/generate");

        $response->assertStatus(202);
        Queue::assertPushed(GenerateAgendaJob::class, function (GenerateAgendaJob $job) use ($event) {
            return $job->calendarEvent->id === $event->id;
        });
    }

    #[Test]
    public function agenda_service_reuses_failed_agendas_instead_of_creating_duplicates(): void
    {
        [$user, $event] = $this->makeEventWithTeam();

        $failedAgenda = MeetingAgenda::create([
            'calendar_event_id' => $event->id,
            'user_id' => null,
            'type' => 'general',
            'status' => AgendaStatus::FAILED,
            'raw_json' => ['old' => 'payload'],
            'content' => 'old content',
            'send_scheduled_at' => now()->subMinutes(30),
        ]);

        $service = $this->app->make(AgendaService::class);
        $service->generateForEvent($event);

        $this->assertSame(1, MeetingAgenda::where('calendar_event_id', $event->id)->count());

        $agenda = MeetingAgenda::where('calendar_event_id', $event->id)->firstOrFail();
        $this->assertSame($failedAgenda->id, $agenda->id);
        $this->assertSame(AgendaStatus::DONE, $agenda->status);
        $this->assertNotEmpty($agenda->raw_json);
        $this->assertNotEmpty($agenda->content);
    }

    /**
     * @return array{0: User, 1: CalendarEvent}
     */
    private function makeEventWithTeam(): array
    {
        $organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ]);

        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => 'employee']);

        $methodology = Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
            ]);

        $team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
        ]);
        $team->users()->attach($user);

        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'test-source-id',
            'identity' => 'test@example.com',
        ]);

        $previousEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'test-event-id-prev',
            'platform' => 'google_meet',
            'title' => 'Test Meeting',
            'url' => 'https://meet.google.com/test',
            'description' => 'Previous meeting',
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subWeek()->addHour(),
            'required_bot' => false,
        ]);

        MeetingSummary::create([
            'calendar_event_id' => $previousEvent->id,
            'status' => 'done',
            'title' => 'Previous Meeting Summary',
            'summary' => 'Previous meeting went well.',
        ]);

        $event = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'test-event-id',
            'platform' => 'google_meet',
            'title' => 'Test Meeting',
            'url' => 'https://meet.google.com/test',
            'description' => 'Test meeting description',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'required_bot' => false,
        ]);

        return [$user, $event];
    }
}
