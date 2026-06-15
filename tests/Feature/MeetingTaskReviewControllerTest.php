<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\MeetingTaskReview;
use App\Models\Organization;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MeetingTaskReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Test Org', 'slug' => 'test-org']);
        $this->user = User::factory()->create();
        $this->user->organizations()->attach($this->org->id, ['role' => 'manager']);

        $source = Source::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'type' => 'google_calendar',
            'external_id' => 'src-test',
            'identity' => 'test@example.com',
        ]);

        $this->event = CalendarEvent::create([
            'source_id' => $source->id,
            'external_id' => 'event-test',
            'platform' => 'google_meet',
            'title' => 'Test Meeting',
            'description' => '',
            'url' => 'https://meet.example.com/test',
            'starts_at' => now(),
            'ends_at' => now()->addHour(),
        ]);
    }

    #[Test]
    public function it_shows_meeting_task_review()
    {
        $review = MeetingTaskReview::create([
            'calendar_event_id' => $this->event->id,
            'organization_id' => $this->org->id,
            'analyzed_count' => 5,
            'status' => 'done',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/calendar-events/{$this->event->id}/task-review");

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'review' => [
                    'id' => $review->id,
                    'calendar_event_id' => $this->event->id,
                    'status' => 'done',
                    'analyzed_count' => 5,
                    'discussed_count' => 0,
                ],
            ],
        ]);

        $data = $response->json('data');
        $this->assertIsArray($data['llm_blocks']);
        $this->assertIsArray($data['health_blocks']);
    }

    #[Test]
    public function it_returns_404_if_review_not_found()
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/calendar-events/{$this->event->id}/task-review");

        $response->assertNotFound();
    }

    #[Test]
    public function it_returns_empty_llm_blocks_when_pending()
    {
        MeetingTaskReview::create([
            'calendar_event_id' => $this->event->id,
            'organization_id' => $this->org->id,
            'analyzed_count' => 0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/calendar-events/{$this->event->id}/task-review");

        $response->assertOk();
        $this->assertIsArray($response->json('data.llm_blocks'));
        $this->assertEmpty($response->json('data.llm_blocks'));
        $this->assertIsArray($response->json('data.health_blocks'));
    }
}
