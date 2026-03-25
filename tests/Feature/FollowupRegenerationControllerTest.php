<?php

namespace Tests\Feature;

use App\Jobs\RegenerateFollowupJob;
use App\Models\CalendarEvent;
use App\Models\Followup;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FollowupRegenerationControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function regenerate_endpoint_queues_a_followup_regeneration_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$calendarEvent, $followup] = $this->createFollowupForUser($user);

        $this->actingAs($user)
            ->postJson("/api/v1/followups/{$followup->id}/regenerate")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.followup_id', $followup->id)
            ->assertJsonPath('data.calendar_event_id', $calendarEvent->id);

        Queue::assertPushed(RegenerateFollowupJob::class, function (RegenerateFollowupJob $job) use ($calendarEvent, $user) {
            return $job->calendarEventId === $calendarEvent->id
                && $job->userId === $user->id;
        });
    }

    private function createFollowupForUser(User $user): array
    {
        $organization = Organization::create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

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

        $followup = Followup::create([
            'calendar_event_id' => $calendarEvent->id,
            'team_id' => $team->id,
            'user_id' => $user->id,
            'methodology_id' => $methodology->id,
            'status' => 'done',
            'text' => json_encode(['summary' => 'Old followup']),
        ]);

        return [$calendarEvent, $followup];
    }
}
