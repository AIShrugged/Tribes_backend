<?php

namespace Tests\Feature;

use App\Events\TranscriptParsed;
use App\Models\CalendarEvent;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscriptUploadTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private Team $team;
    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org  = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->user = User::factory()->create();
        $this->org->users()->attach($this->user, ['role' => 'employee']);

        $methodology = Methodology::create([
            'name'            => 'Test Methodology',
            'text'            => 'Analyze.',
            'scheme'          => json_encode(['type' => 'object']),
            'organization_id' => $this->org->id,
        ]);

        $this->team = Team::create([
            'name'            => 'TechSync',
            'slug'            => 'techsync',
            'organization_id' => $this->org->id,
            'methodology_id'  => $methodology->id,
        ]);
        $this->team->users()->attach($this->user);

        $this->source = Source::create([
            'user_id'         => $this->user->id,
            'type'            => 'google_calendar',
            'external_id'     => 'src-1',
            'identity'        => 'u@example.com',
            'organization_id' => $this->org->id,
        ]);

        Event::fake([TranscriptParsed::class]);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/transcripts/' . $name);
    }

    private function uploadFile(string $fixtureName, string $clientName, string $mime): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($clientName, $this->fixture($fixtureName))
            ->mimeType($mime);
    }

    // ── 1. Happy paths per format ──

    #[Test]
    public function uploads_recall_json_to_new_event_and_fires_event(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'      => $this->uploadFile('recall_sample.json', 'transcript.json', 'application/json'),
                'team_id'   => $this->team->id,
                'title'     => 'Test sync',
                'starts_at' => now()->subHour()->toIso8601String(),
                'ends_at'   => now()->toIso8601String(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.transcript_entries_count', 2);
        $response->assertJsonPath('data.participants_count', 2);

        $eventId = $response->json('data.calendar_event_id');
        $this->assertDatabaseHas('calendar_events', [
            'id'              => $eventId,
            'creator_user_id' => $this->user->id,
            'platform'        => 'manual_upload',
        ]);
        $this->assertDatabaseCount('participants', 2);
        $this->assertDatabaseCount('transcript_entries', 2);

        Event::assertDispatched(TranscriptParsed::class, fn ($e) => $e->calendarEvent->id === $eventId);
    }

    #[Test]
    public function uploads_plain_text_with_synthesized_timings(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'      => $this->uploadFile('plain_simple.txt', 'transcript.txt', 'text/plain'),
                'team_id'   => $this->team->id,
                'title'     => 'Plain sync',
                'starts_at' => now()->subHour()->toIso8601String(),
                'ends_at'   => now()->toIso8601String(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.transcript_entries_count', 4);

        // 4 entries spread over 1h meeting → step = 3600/4 = 900s (floor of 15s doesn't bind).
        $eventId = $response->json('data.calendar_event_id');
        $entries = \App\Models\TranscriptEntry::where('calendar_event_id', $eventId)
            ->orderBy('start_relative')->pluck('start_relative')->map(fn ($v) => (float) $v)->all();
        $this->assertEquals([0.0, 900.0, 1800.0, 2700.0], $entries);
    }

    #[Test]
    public function uploads_timestamped_txt_dialect_preserving_timestamps(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'      => $this->uploadFile('plain_with_timestamps.txt', 't.txt', 'text/plain'),
                'team_id'   => $this->team->id,
                'title'     => 'Stamped sync',
                'starts_at' => now()->subHour()->toIso8601String(),
                'ends_at'   => now()->toIso8601String(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.transcript_entries_count', 4);

        $eventId = $response->json('data.calendar_event_id');
        $firstRel = \App\Models\TranscriptEntry::where('calendar_event_id', $eventId)
            ->orderBy('id')->value('start_relative');
        // [14:00] = 14*3600 = 50400. Real timing, no synthesis.
        $this->assertEqualsWithDelta(50400.0, (float) $firstRel, 0.01);
    }

    #[Test]
    public function uploads_vtt(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'      => $this->uploadFile('sample.vtt', 'cap.vtt', 'text/vtt'),
                'team_id'   => $this->team->id,
                'title'     => 'VTT sync',
                'starts_at' => now()->subHour()->toIso8601String(),
                'ends_at'   => now()->toIso8601String(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.transcript_entries_count', 3);
    }

    #[Test]
    public function uploads_srt(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'      => $this->uploadFile('sample.srt', 'cap.srt', 'application/x-subrip'),
                'team_id'   => $this->team->id,
                'title'     => 'SRT sync',
                'starts_at' => now()->subHour()->toIso8601String(),
                'ends_at'   => now()->toIso8601String(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.transcript_entries_count', 3);
    }

    // ── 2. Existing event path ──

    #[Test]
    public function uploads_to_existing_event_replaces_transcript(): void
    {
        $event = CalendarEvent::create([
            'source_id'    => $this->source->id,
            'external_id'  => 'existing-1',
            'platform'     => 'google_meet',
            'title'        => 'Existing',
            'description'  => '',
            'url'          => 'https://meet.google.com/x',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);
        \App\Models\Participant::create(['calendar_event_id' => $event->id, 'name' => 'OldSpeaker']);

        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'              => $this->uploadFile('plain_simple.txt', 't.txt', 'text/plain'),
                'calendar_event_id' => $event->id,
            ]);

        $response->assertCreated();
        $this->assertDatabaseMissing('participants', ['name' => 'OldSpeaker']);
        $this->assertDatabaseCount('participants', 3);  // new speakers from plain_simple.txt
    }

    // ── 3. Auth failure cases ──

    #[Test]
    public function rejects_upload_to_event_in_other_org(): void
    {
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $otherUser = User::factory()->create();
        $otherOrg->users()->attach($otherUser, ['role' => 'employee']);

        $otherSource = Source::create([
            'user_id'         => $otherUser->id,
            'type'            => 'google_calendar',
            'external_id'     => 'other-src',
            'identity'        => 'other@example.com',
            'organization_id' => $otherOrg->id,
        ]);

        $foreignEvent = CalendarEvent::create([
            'source_id'    => $otherSource->id,
            'external_id'  => 'foreign-1',
            'platform'     => 'google_meet',
            'title'        => 'Foreign',
            'description'  => '',
            'url'          => 'https://meet.google.com/y',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'              => $this->uploadFile('plain_simple.txt', 't.txt', 'text/plain'),
                'calendar_event_id' => $foreignEvent->id,
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function rejects_when_uploader_has_no_source(): void
    {
        $userWithoutSource = User::factory()->create();
        $this->org->users()->attach($userWithoutSource, ['role' => 'employee']);
        $this->team->users()->attach($userWithoutSource);

        $response = $this->actingAs($userWithoutSource)
            ->post(route('transcripts.upload'), [
                'file'      => $this->uploadFile('plain_simple.txt', 't.txt', 'text/plain'),
                'team_id'   => $this->team->id,
                'title'     => 'No source',
                'starts_at' => now()->subHour()->toIso8601String(),
                'ends_at'   => now()->toIso8601String(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_code', 'NO_SOURCE');
    }

    #[Test]
    public function rejects_team_in_other_organization(): void
    {
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other2']);
        $foreignMethodology = Methodology::create([
            'name'            => 'M2',
            'text'            => '.',
            'scheme'          => json_encode(['type' => 'object']),
            'organization_id' => $otherOrg->id,
        ]);
        $foreignTeam = Team::create([
            'name'            => 'Foreign',
            'slug'            => 'foreign',
            'organization_id' => $otherOrg->id,
            'methodology_id'  => $foreignMethodology->id,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'      => $this->uploadFile('plain_simple.txt', 't.txt', 'text/plain'),
                'team_id'   => $foreignTeam->id,
                'title'     => 'Foreign team',
                'starts_at' => now()->subHour()->toIso8601String(),
                'ends_at'   => now()->toIso8601String(),
            ]);

        $response->assertForbidden();
    }

    // ── 4. Validation cases ──

    #[Test]
    public function rejects_missing_title_when_creating_event(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('transcripts.upload'), [
                'team_id'   => $this->team->id,
                'starts_at' => now()->subHour()->toIso8601String(),
                'ends_at'   => now()->toIso8601String(),
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function rejects_ends_at_before_starts_at(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'      => $this->uploadFile('plain_simple.txt', 't.txt', 'text/plain'),
                'team_id'   => $this->team->id,
                'title'     => 'Bad timing',
                'starts_at' => now()->toIso8601String(),
                'ends_at'   => now()->subHour()->toIso8601String(),
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function auto_selects_team_when_user_has_exactly_one(): void
    {
        // user already has exactly one team via setUp — omit team_id and expect success.
        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'      => $this->uploadFile('plain_simple.txt', 't.txt', 'text/plain'),
                'title'     => 'Auto-team',
                'starts_at' => now()->subHour()->toIso8601String(),
                'ends_at'   => now()->toIso8601String(),
            ]);

        $response->assertCreated();
    }

    // ── 5. Format detection edge cases ──

    #[Test]
    public function detects_recall_json_even_with_utf8_bom(): void
    {
        $contentsWithBom = "\xEF\xBB\xBF" . $this->fixture('recall_sample.json');
        $file = UploadedFile::fake()->createWithContent('with-bom.json', $contentsWithBom)
            ->mimeType('application/json');

        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'      => $file,
                'team_id'   => $this->team->id,
                'title'     => 'BOM',
                'starts_at' => now()->subHour()->toIso8601String(),
                'ends_at'   => now()->toIso8601String(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.transcript_entries_count', 2);
    }

    // ── 6. Empty / zero-entry guard ──

    #[Test]
    public function rejects_file_that_parses_to_zero_entries(): void
    {
        $file = UploadedFile::fake()->createWithContent('empty.txt', "# only a comment\n")
            ->mimeType('text/plain');

        $event = CalendarEvent::create([
            'source_id'    => $this->source->id,
            'external_id'  => 'preserve-me',
            'platform'     => 'google_meet',
            'title'        => 'Existing',
            'description'  => '',
            'url'          => 'https://meet.google.com/keep',
            'starts_at'    => now(),
            'ends_at'      => now()->addHour(),
            'required_bot' => false,
        ]);
        \App\Models\Participant::create(['calendar_event_id' => $event->id, 'name' => 'Survivor']);

        $response = $this->actingAs($this->user)
            ->post(route('transcripts.upload'), [
                'file'              => $file,
                'calendar_event_id' => $event->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_code', 'TRANSCRIPT_PARSE_FAILED');

        // Critical: existing participant must NOT be wiped by a failed upload.
        $this->assertDatabaseHas('participants', ['name' => 'Survivor']);
    }
}
