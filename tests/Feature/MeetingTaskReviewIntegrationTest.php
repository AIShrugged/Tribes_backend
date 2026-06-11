<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Source;
use App\Models\TranscriptEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingTaskReviewIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function full_workflow_from_transcript_to_api()
    {
        // Setup
        $org = Organization::create(['name' => 'Tech Org', 'slug' => 'tech-org']);
        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'manager']);

        $source = Source::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => 'google_calendar',
            'external_id' => 'src-tech',
            'identity' => 'tech@example.com',
        ]);

        // Create a meeting event
        $event = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-tech',
            'platform' => 'google_meet',
            'title' => 'Sprint Review',
            'description' => 'Weekly sprint review meeting',
            'url' => 'https://meet.example.com/tech',
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
        ]);

        // Add transcript
        $participant = Participant::create([
            'calendar_event_id' => $event->id,
            'name' => 'Alice Engineer',
        ]);

        TranscriptEntry::create([
            'calendar_event_id' => $event->id,
            'participant_id' => $participant->id,
            'text' => 'The login feature is completely done and merged to main',
            'start_relative' => 0,
            'end_relative' => 10,
            'start_absolute' => $event->starts_at,
            'end_absolute' => $event->starts_at->addSeconds(10),
        ]);

        // Create test issues
        $doneIssue = Issue::create([
            'organization_id' => $org->id,
            'name' => 'Implement login feature',
            'status' => 'in_progress',
            'user_id' => $user->id,
            'assignee_id' => $user->id,
            'description' => 'OAuth login implementation',
        ]);

        $noAssigneeIssue = Issue::create([
            'organization_id' => $org->id,
            'name' => 'Write documentation',
            'status' => 'open',
            'user_id' => $user->id,
            'assignee_id' => null,
            'description' => 'API documentation',
        ]);

        $overdueIssue = Issue::create([
            'organization_id' => $org->id,
            'name' => 'Fix performance issue',
            'status' => 'in_progress',
            'user_id' => $user->id,
            'assignee_id' => $user->id,
            'description' => 'Optimize database queries',
            'due_date' => now()->subDays(5),
        ]);

        // Dispatch transcript parsed event (this would normally trigger the listener)
        // For testing, we'll directly call the service
        $service = app(\App\Services\Issue\MeetingTaskReviewService::class);
        $review = $service->generate($event, $org->id);

        // Verify review was created
        $this->assertNotNull($review);
        $this->assertEquals($event->id, $review->calendar_event_id);
        $this->assertEquals($org->id, $review->organization_id);
        $this->assertContains($review->status, ['done', 'pending', 'failed']);
        $this->assertGreaterThan(0, $review->analyzed_count);

        // Get health blocks (always available, no LLM needed)
        $healthBlocks = $service->getHealthBlocks($review->organization_id);

        $this->assertIsArray($healthBlocks);

        $blockTypes = array_column($healthBlocks, 'type');
        $this->assertContains('no_assignee', $blockTypes, 'Should identify issue without assignee');
        $this->assertContains('overdue', $blockTypes, 'Should identify overdue issue');

        // Test API endpoint
        $response = $this->actingAs($user)
            ->getJson("/api/v1/calendar-events/{$event->id}/task-review");

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'review' => [
                    'calendar_event_id' => $event->id,
                    'status' => $review->status,
                ],
            ],
        ]);

        $data = $response->json('data');
        $this->assertIsArray($data['health_blocks']);
        $this->assertIsArray($data['llm_blocks']);
    }
}
