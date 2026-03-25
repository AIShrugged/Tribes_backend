<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Source;
use App\Models\Team;
use App\Models\TranscriptEntry;
use App\Models\User;
use App\Jobs\RegenerateFollowupJob;
use App\Services\Agent\AgentToolRegistrar;
use App\Services\Agent\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FollowupRegenerationToolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function agent_can_regenerate_followup_through_registry_tool(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$organization, $team, $calendarEvent] = $this->createFollowupContextFor($user);

        $newMethodology = Methodology::create([
            'name' => 'Updated Methodology',
            'text' => 'Updated methodology text',
            'scheme' => json_encode([
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                ],
            ]),
            'organization_id' => $organization->id,
        ]);

        $team->update(['methodology_id' => $newMethodology->id]);

        $oldFollowup = Followup::create([
            'calendar_event_id' => $calendarEvent->id,
            'team_id' => $team->id,
            'user_id' => $user->id,
            'methodology_id' => $this->defaultMethodology($organization)->id,
            'status' => 'done',
            'text' => json_encode(['summary' => 'Old followup']),
        ]);

        $registry = new ToolRegistry;
        $this->app->make(AgentToolRegistrar::class)->registerDefaults(
            $registry,
            $user,
            'web',
            organizationId: $organization->id,
            teamId: $team->id,
        );

        $result = $registry->get('regenerate_followup')?->execute([
            'calendar_event_id' => $calendarEvent->id,
        ]);

        $this->assertTrue((bool) data_get($result, 'success'));
        $this->assertSame($oldFollowup->id, (int) data_get($result, 'old_followup_id'));
        $this->assertSame('in_progress', data_get($result, 'status'));
        Queue::assertPushed(RegenerateFollowupJob::class, function (RegenerateFollowupJob $job) use ($calendarEvent, $user) {
            return $job->calendarEventId === $calendarEvent->id
                && $job->userId === $user->id;
        });

        $this->assertSame(1, Followup::query()->where('calendar_event_id', $calendarEvent->id)->count());
    }

    #[Test]
    public function regenerate_followup_requires_calendar_event_id(): void
    {
        $user = User::factory()->create();
        [$organization, $team, $calendarEvent] = $this->createFollowupContextFor($user);

        $registry = new ToolRegistry;
        $this->app->make(AgentToolRegistrar::class)->registerDefaults(
            $registry,
            $user,
            'web',
            organizationId: $organization->id,
            teamId: $team->id,
        );

        $result = $registry->get('regenerate_followup')?->execute([]);

        $this->assertFalse((bool) data_get($result, 'success'));
        $this->assertSame('calendar_event_id is required', data_get($result, 'error'));
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
