<?php

namespace Tests\Feature;

use App\Domain\DTO\TranscriptEntryDTO;
use App\Events\TranscriptParsed;
use App\Jobs\ParseTranscriptJob;
use App\Models\CalendarEvent;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Source;
use App\Models\TranscriptEntry;
use App\Models\User;
use App\Services\RecallTranscriptParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ParseTranscriptJobTest extends TestCase
{
    use RefreshDatabase;

    private CalendarEvent $event;

    protected function setUp(): void
    {
        parent::setUp();

        $org  = Organization::create(['name' => 'Transcript Org', 'slug' => 'transcript-org']);
        $user = User::factory()->create();
        $org->users()->attach($user, ['role' => 'employee']);

        $source = Source::create([
            'user_id'     => $user->id,
            'type'        => 'google_calendar',
            'external_id' => 'transcript-src',
            'identity'    => 'user@example.com',
        ]);

        $this->event = CalendarEvent::create([
            'source_id'    => $source->id,
            'external_id'  => 'transcript-event',
            'platform'     => 'google_meet',
            'title'        => 'Meeting',
            'description'  => '',
            'url'          => 'https://meet.google.com/x',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);
    }

    // ── 1. Download failure: existing entries must not be deleted ──

    #[Test]
    public function download_failure_preserves_existing_transcript_entries(): void
    {
        $participant = Participant::create([
            'calendar_event_id' => $this->event->id,
            'name'              => 'Alice',
        ]);
        TranscriptEntry::create([
            'calendar_event_id' => $this->event->id,
            'participant_id'    => $participant->id,
            'text'              => 'Pre-existing entry',
            'start_relative'    => 0.0,
            'end_relative'      => 5.0,
            'start_absolute'    => now(),
            'end_absolute'      => now()->addSeconds(5),
        ]);

        Http::fake(['recall-cdn.example.com/*' => Http::response(null, 500)]);

        $this->expectException(\App\Exceptions\AppException::class);

        $job = new ParseTranscriptJob($this->event, 'https://recall-cdn.example.com/transcript.json');
        $job->handle(
            $this->app->make(RecallTranscriptParser::class),
            $this->app->make(\App\Services\Transcript\TranscriptPersistenceService::class),
        );

        $this->assertDatabaseCount('transcript_entries', 1);
        $this->assertDatabaseHas('transcript_entries', ['text' => 'Pre-existing entry']);
    }

    // ── 2. Successful parse replaces entries ──

    #[Test]
    public function successful_parse_replaces_old_entries(): void
    {
        $participant = Participant::create([
            'calendar_event_id' => $this->event->id,
            'name'              => 'OldSpeaker',
        ]);
        TranscriptEntry::create([
            'calendar_event_id' => $this->event->id,
            'participant_id'    => $participant->id,
            'text'              => 'Old entry',
            'start_relative'    => 0.0,
            'end_relative'      => 3.0,
            'start_absolute'    => now(),
            'end_absolute'      => now()->addSeconds(3),
        ]);

        $payload = $this->makePayload('Bob', 'Hello');
        Http::fake(['recall-cdn.example.com/*' => Http::response($payload, 200)]);

        $job = new ParseTranscriptJob($this->event, 'https://recall-cdn.example.com/transcript.json');
        $job->handle(
            $this->app->make(RecallTranscriptParser::class),
            $this->app->make(\App\Services\Transcript\TranscriptPersistenceService::class),
        );

        $this->assertDatabaseMissing('transcript_entries', ['text' => 'Old entry']);
        $this->assertDatabaseHas('participants', ['name' => 'Bob']);
        $this->assertDatabaseCount('participants', 1);
    }

    // ── 3. TranscriptParsed event fired only after successful commit ──

    #[Test]
    public function transcript_parsed_event_fired_after_commit(): void
    {
        Event::fake([TranscriptParsed::class]);

        $payload = $this->makePayload('Carol', 'Hi');

        Http::fake(['recall-cdn.example.com/*' => Http::response($payload, 200)]);

        $job = new ParseTranscriptJob($this->event, 'https://recall-cdn.example.com/transcript.json');
        $job->handle(
            $this->app->make(RecallTranscriptParser::class),
            $this->app->make(\App\Services\Transcript\TranscriptPersistenceService::class),
        );

        Event::assertDispatched(TranscriptParsed::class, function ($e) {
            return $e->calendarEvent->id === $this->event->id;
        });
    }

    private function makePayload(string $speaker, string $text): array
    {
        $base = now()->toIso8601String();
        $end  = now()->addSeconds(2)->toIso8601String();

        return [
            [
                'participant' => ['name' => $speaker],
                'words'       => [
                    [
                        'text'            => $text,
                        'start_timestamp' => ['relative' => 0.0, 'absolute' => $base],
                        'end_timestamp'   => ['relative' => 2.0, 'absolute' => $end],
                    ],
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
