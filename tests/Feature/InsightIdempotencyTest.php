<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Channel;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Profile;
use App\Models\Source;
use App\Models\TranscriptEntry;
use App\Models\User;
use App\Services\Insight\InsightExtractionService;
use App\Services\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InsightIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();

        $org  = Organization::create(['name' => 'Insight Org', 'slug' => 'insight-org']);
        $user = User::factory()->create();
        $org->users()->attach($user, ['role' => 'employee']);

        $source = Source::create([
            'user_id'     => $user->id,
            'type'        => 'google_calendar',
            'external_id' => 'insight-src',
            'identity'    => 'owner@example.com',
        ]);

        $this->event = CalendarEvent::create([
            'source_id'    => $source->id,
            'external_id'  => 'insight-event',
            'platform'     => 'google_meet',
            'title'        => 'Team Sync',
            'description'  => '',
            'url'          => 'https://meet.google.com/x',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);

        $gcChannel = Channel::where('name', 'google_calendar')->firstOrFail();

        $profile = Profile::create([
            'user_id'            => $user->id,
            'channel_id'         => $gcChannel->id,
            'channel_identifier' => 'john@example.com',
        ]);

        $participant = Participant::create([
            'calendar_event_id' => $this->event->id,
            'name'              => 'John Doe',
            'profile_id'        => $profile->id,
        ]);

        TranscriptEntry::create([
            'calendar_event_id' => $this->event->id,
            'participant_id'    => $participant->id,
            'text'              => 'Let us discuss the sprint.',
            'start_relative'    => 0.0,
            'end_relative'      => 5.0,
            'start_absolute'    => now(),
            'end_absolute'      => now()->addSeconds(5),
        ]);
    }

    private function mockLlmWithOneItem(): void
    {
        $mock = Mockery::mock(OpenRouterClient::class);
        $mock->shouldReceive('chat')->andReturn(json_encode([
            'participants' => [
                [
                    'identifier' => 'john@example.com',
                    'name'       => 'John Doe',
                    'items'      => [
                        [
                            'category'   => 'strengths',
                            'fact'       => 'Excellent at debugging',
                            'confidence' => 0.9,
                        ],
                    ],
                    'short_term' => [],
                ],
            ],
            'relationships' => [],
        ]));
        $this->app->instance(OpenRouterClient::class, $mock);
    }

    #[Test]
    public function first_run_creates_insight_items(): void
    {
        $this->mockLlmWithOneItem();

        $sources = $this->app->make(InsightExtractionService::class)->extract($this->event);

        $this->assertCount(1, $sources);
        $this->assertDatabaseCount('insight_items', 1);
        $this->assertDatabaseHas('insight_items', ['fact' => 'Excellent at debugging']);
    }

    #[Test]
    public function second_run_replaces_not_appends_items(): void
    {
        $this->mockLlmWithOneItem();

        $service = $this->app->make(InsightExtractionService::class);
        $service->extract($this->event);
        $this->assertDatabaseCount('insight_items', 1);

        // Second run must not append — items are replaced (Q5 regression)
        $service->extract($this->event);
        $this->assertDatabaseCount('insight_items', 1);
    }

    #[Test]
    public function empty_transcript_skips_extraction(): void
    {
        TranscriptEntry::where('calendar_event_id', $this->event->id)->delete();

        $sources = $this->app->make(InsightExtractionService::class)->extract($this->event);

        $this->assertEmpty($sources);
        $this->assertDatabaseCount('insight_items', 0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
