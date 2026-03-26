<?php

namespace Tests\Feature;

use App\Jobs\RegenerateFollowupJob;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Source;
use App\Models\Team;
use App\Models\TranscriptEntry;
use App\Models\User;
use App\Services\Followup\FollowupService;
use App\Services\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegenerateFollowupJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function job_regenerates_followup_asynchronously_when_handled(): void
    {
        $user = User::factory()->create();
        [$organization, $team, $calendarEvent] = $this->createFollowupContextFor($user);

        $newMethodology = Methodology::create([
            'name' => 'Updated Methodology',
            'text' => 'Updated methodology text',
            'scheme' => '{}',
            'organization_id' => $organization->id,
        ]);
        $team->update(['methodology_id' => $newMethodology->id]);

        Followup::create([
            'calendar_event_id' => $calendarEvent->id,
            'team_id' => $team->id,
            'user_id' => $user->id,
            'methodology_id' => $this->defaultMethodology($organization)->id,
            'status' => 'done',
            'text' => json_encode(['summary' => 'Old followup']),
        ]);

        $mockClient = Mockery::mock(OpenRouterClient::class);
        $mockClient->shouldReceive('chat')
            ->once()
            ->andReturn(json_encode(['summary' => 'Regenerated followup']));

        $this->app->instance(OpenRouterClient::class, $mockClient);

        $job = new RegenerateFollowupJob($calendarEvent->id, $user->id);
        $job->handle($this->app->make(FollowupService::class));

        $followups = Followup::query()
            ->where('calendar_event_id', $calendarEvent->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $followups);
        $this->assertSame('done', $followups->last()->status);
        $this->assertSame($newMethodology->id, $followups->last()->methodology_id);
    }

    private function createFollowupContextFor(User $user): array
    {
        $organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $methodology = $this->defaultMethodology($organization);

        $team = Team::create([
            'organization_id' => $organization->id,
            'methodology_id' => $methodology->id,
            'name' => 'Platform',
            'slug' => 'platform',
        ]);

        $organization->users()->attach($user->id, ['role' => 'manager']);
        $team->users()->attach($user->id);

        $source = Source::create([
            'user_id' => $user->id,
            'type' => 'google_calendar',
            'external_id' => 'source-1',
            'identity' => 'user@example.com',
        ]);

        $calendarEvent = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-1',
            'platform' => 'google_meet',
            'title' => 'Weekly Sync',
            'url' => 'https://meet.google.com/test',
            'description' => 'Weekly sync description',
            'starts_at' => now()->subHour(),
            'ends_at' => now(),
            'required_bot' => false,
        ]);

        $participant = Participant::create([
            'calendar_event_id' => $calendarEvent->id,
            'name' => 'John Doe',
        ]);

        TranscriptEntry::create([
            'calendar_event_id' => $calendarEvent->id,
            'participant_id' => $participant->id,
            'text' => 'We discussed the roadmap.',
            'start_relative' => 0.0,
            'end_relative' => 5.0,
            'start_absolute' => now()->subMinutes(50),
            'end_absolute' => now()->subMinutes(49),
        ]);

        return [$organization, $team, $calendarEvent];
    }

    private function defaultMethodology(Organization $organization): Methodology
    {
        return Methodology::query()->where('is_default', true)->first()
            ?? Methodology::create([
                'name' => 'Default Methodology',
                'text' => 'Default methodology text',
                'scheme' => '{}',
                'is_default' => true,
                'organization_id' => $organization->id,
            ]);
    }
}
