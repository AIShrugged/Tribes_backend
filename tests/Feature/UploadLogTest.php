<?php

namespace Tests\Feature;

use App\Events\TranscriptParsed;
use App\Models\Issue;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Source;
use App\Models\TaskDataUpload;
use App\Models\Team;
use App\Models\TranscriptUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UploadLogTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private Team $team;
    private Source $source;

    private Organization $otherOrg;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org  = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->user = User::factory()->create();
        $this->org->users()->attach($this->user, ['role' => 'employee']);

        $methodology = Methodology::create([
            'name'            => 'M',
            'text'            => '.',
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

        $this->otherOrg  = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $this->otherUser = User::factory()->create();
        $this->otherOrg->users()->attach($this->otherUser, ['role' => 'employee']);

        Event::fake([TranscriptParsed::class]);
    }

    private function vtt(string $clientName = 'cap.vtt'): UploadedFile
    {
        $content = "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\n<v Alice>Hello everyone</v>\n\n"
            . "00:00:02.000 --> 00:00:05.000\n<v Bob>Hi Alice</v>\n";

        return UploadedFile::fake()->createWithContent($clientName, $content)->mimeType('text/vtt');
    }

    private function makeTranscriptUpload(array $attrs = []): TranscriptUpload
    {
        return TranscriptUpload::create(array_merge([
            'user_id'           => $this->user->id,
            'organization_id'   => $this->org->id,
            'original_filename' => 'meeting.vtt',
            'status'            => 'done',
        ], $attrs));
    }

    private function makeTaskDataUpload(array $attrs = []): TaskDataUpload
    {
        return TaskDataUpload::create(array_merge([
            'user_id'           => $this->user->id,
            'team_id'           => $this->team->id,
            'organization_id'   => $this->org->id,
            'original_filename' => 'tasks.txt',
            'status'            => 'done',
            'issues_created'    => 2,
            'issues_updated'    => 1,
        ], $attrs));
    }

    // ── Feed ──

    #[Test]
    public function merged_feed_lists_both_types_sorted_desc_with_items_count(): void
    {
        $this->makeTaskDataUpload(['created_at' => now()->subHour()]);
        $this->makeTranscriptUpload(['created_at' => now()]);

        $response = $this->actingAs($this->user)->getJson(route('uploads.index', ['limit' => 50]));

        $response->assertOk();
        $response->assertHeader('Items-Count', 2);
        $response->assertJsonCount(2, 'data');
        // Newest first: transcript (now) before task_data (1h ago).
        $response->assertJsonPath('data.0.type', 'transcript');
        $response->assertJsonPath('data.1.type', 'task_data');
        // Discriminated, null-filled fields.
        $response->assertJsonPath('data.0.team_name', null);
        $response->assertJsonPath('data.1.team_name', 'TechSync');
        $response->assertJsonPath('data.1.issues_created', 2);
    }

    #[Test]
    public function feed_exposes_normalized_status(): void
    {
        $this->makeTaskDataUpload(['status' => 'analyzing', 'created_at' => now()]);
        $this->makeTranscriptUpload(['status' => 'pending', 'created_at' => now()->subMinute()]);

        $response = $this->actingAs($this->user)->getJson(route('uploads.index', ['limit' => 50]));

        $response->assertOk();
        $response->assertJsonPath('data.0.status', 'processing'); // analyzing -> processing
        $response->assertJsonPath('data.1.status', 'processing'); // pending   -> processing
    }

    #[Test]
    public function status_filter_translates_to_raw_statuses(): void
    {
        $this->makeTaskDataUpload(['status' => 'failed', 'error_message' => 'Could not process uploaded file']);
        $this->makeTaskDataUpload(['status' => 'done']);

        $response = $this->actingAs($this->user)->getJson(route('uploads.index', ['limit' => 50, 'status' => 'failed']));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.status', 'failed');
        $response->assertJsonPath('data.0.error_message', 'Could not process uploaded file');
    }

    // ── Visibility / scoping ──

    #[Test]
    public function org_member_sees_org_uploads_but_other_org_does_not(): void
    {
        $this->makeTaskDataUpload();
        $this->makeTranscriptUpload();

        $teammate = User::factory()->create();
        $this->org->users()->attach($teammate, ['role' => 'employee']);
        $this->team->users()->attach($teammate);

        $this->actingAs($teammate)->getJson(route('uploads.index', ['limit' => 50]))
            ->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($this->otherUser)->getJson(route('uploads.index', ['limit' => 50]))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function detail_endpoint_returns_404_for_upload_outside_scope(): void
    {
        $upload = $this->makeTaskDataUpload();

        $this->actingAs($this->otherUser)
            ->getJson(route('uploads.show', ['type' => 'task_data', 'id' => $upload->id]))
            ->assertNotFound();
    }

    #[Test]
    public function legacy_status_endpoint_is_idor_safe(): void
    {
        $upload = $this->makeTaskDataUpload();

        // Owner can read it.
        $this->actingAs($this->user)
            ->getJson(route('tasks.upload.status', ['uploadId' => $upload->id]))
            ->assertOk();

        // A user from another org cannot (was leaking before the B5 fix).
        $this->actingAs($this->otherUser)
            ->getJson(route('tasks.upload.status', ['uploadId' => $upload->id]))
            ->assertNotFound();
    }

    #[Test]
    public function task_data_detail_refilters_issue_names_through_issue_visibility(): void
    {
        $upload = $this->makeTaskDataUpload();
        Issue::create([
            'name'            => 'Secret task',
            'type'            => Issue::TYPE_BACKEND,
            'status'          => 'open',
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'user_id'         => $this->user->id,
            'sourceable_type' => TaskDataUpload::class,
            'sourceable_id'   => $upload->id,
        ]);

        // Uploader leaves the org/team: still sees their OWN row (ungated user_id),
        // but the created issue is no longer visible to them, so its name is withheld.
        $this->team->users()->detach($this->user);
        $this->org->users()->detach($this->user);

        $response = $this->actingAs($this->user)
            ->getJson(route('uploads.show', ['type' => 'task_data', 'id' => $upload->id]));

        $response->assertOk();
        $response->assertJsonPath('data.status', 'done');
        $response->assertJsonCount(0, 'data.issues');
    }

    #[Test]
    public function task_data_detail_lists_updated_issues_separately_from_created(): void
    {
        // A pre-existing issue (null sourceable — created elsewhere) that this upload
        // merely updated: it is found via the snapshot of ids, not the morph.
        $updated = Issue::create([
            'name'            => 'Pre-existing task that was updated',
            'type'            => Issue::TYPE_BACKEND,
            'status'          => 'open',
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'user_id'         => $this->user->id,
            'sourceable_type' => null,
            'sourceable_id'   => null,
        ]);

        // A 999999 id that no longer exists must silently drop out of the list.
        $upload = $this->makeTaskDataUpload(['updated_issue_ids' => [$updated->id, 999999]]);

        $created = Issue::create([
            'name'            => 'Freshly created task',
            'type'            => Issue::TYPE_BACKEND,
            'status'          => 'open',
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'user_id'         => $this->user->id,
            'sourceable_type' => TaskDataUpload::class,
            'sourceable_id'   => $upload->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('uploads.show', ['type' => 'task_data', 'id' => $upload->id]));

        $response->assertOk();
        // Created list holds only the created issue; updated list only the updated one.
        $response->assertJsonCount(1, 'data.issues');
        $response->assertJsonPath('data.issues.0.id', $created->id);
        $response->assertJsonCount(1, 'data.updated_issues');
        $response->assertJsonPath('data.updated_issues.0.id', $updated->id);
        $response->assertJsonPath('data.updated_issues.0.name', 'Pre-existing task that was updated');
        $response->assertJsonPath('data.updated_issues.0.status', 'updated');
    }

    #[Test]
    public function task_data_detail_refilters_updated_issue_names_through_visibility(): void
    {
        $updated = Issue::create([
            'name'            => 'Sensitive updated task',
            'type'            => Issue::TYPE_BACKEND,
            'status'          => 'open',
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'user_id'         => $this->user->id,
            'sourceable_type' => null,
            'sourceable_id'   => null,
        ]);
        $upload = $this->makeTaskDataUpload(['updated_issue_ids' => [$updated->id]]);

        // Uploader leaves the org/team: still sees their OWN row (ungated user_id) and
        // its recorded count, but the updated issue name is withheld.
        $this->team->users()->detach($this->user);
        $this->org->users()->detach($this->user);

        $response = $this->actingAs($this->user)
            ->getJson(route('uploads.show', ['type' => 'task_data', 'id' => $upload->id]));

        $response->assertOk();
        $response->assertJsonPath('data.status', 'done');
        $response->assertJsonPath('data.issues_updated', 1); // count preserved (honest)
        $response->assertJsonCount(0, 'data.updated_issues'); // names withheld
    }

    // ── Transcript lifecycle (records the upload-log row) ──

    #[Test]
    public function legacy_status_endpoint_refilters_issue_names(): void
    {
        $upload = $this->makeTaskDataUpload();
        Issue::create([
            'name'            => 'Secret task',
            'type'            => Issue::TYPE_BACKEND,
            'status'          => 'open',
            'organization_id' => $this->org->id,
            'team_id'         => $this->team->id,
            'user_id'         => $this->user->id,
            'sourceable_type' => TaskDataUpload::class,
            'sourceable_id'   => $upload->id,
        ]);

        // Uploader leaves the org/team: still owns the row (so the legacy status
        // endpoint returns 200), but must NOT receive the team-scoped issue name.
        $this->team->users()->detach($this->user);
        $this->org->users()->detach($this->user);

        $response = $this->actingAs($this->user)
            ->getJson(route('tasks.upload.status', ['uploadId' => $upload->id]));

        $response->assertOk();
        $response->assertJsonCount(0, 'data.issues');
    }

    #[Test]
    public function transcript_done_row_falls_back_to_team_org_when_source_has_no_org(): void
    {
        // Multi-org uploader whose source carries no org → resolveOrganizationId()
        // returns null; the done row must still land with the selected team's org so
        // it stays visible in the org-wide log (not org=null / uploader-only).
        $secondOrg = Organization::create(['name' => 'Second', 'slug' => 'second-org']);
        $secondOrg->users()->attach($this->user, ['role' => 'employee']);

        $orglessSourceUser = User::factory()->create();
        $this->org->users()->attach($orglessSourceUser, ['role' => 'employee']);
        $secondOrg->users()->attach($orglessSourceUser, ['role' => 'employee']);
        $this->team->users()->attach($orglessSourceUser);
        Source::create([
            'user_id'         => $orglessSourceUser->id,
            'type'            => 'google_calendar',
            'external_id'     => 'orgless-src',
            'identity'        => 'orgless@example.com',
            'organization_id' => null,
        ]);

        $response = $this->actingAs($orglessSourceUser)->post(route('transcripts.upload'), [
            'file'      => $this->vtt(),
            'team_id'   => $this->team->id,
            'title'     => 'Orgless source sync',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at'   => now()->toIso8601String(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('transcript_uploads', [
            'user_id'         => $orglessSourceUser->id,
            'status'          => 'done',
            'organization_id' => $this->org->id,
        ]);
    }

    #[Test]
    public function transcript_upload_records_done_log_row_with_event_and_counts(): void
    {
        $response = $this->actingAs($this->user)->post(route('transcripts.upload'), [
            'file'      => $this->vtt(),
            'team_id'   => $this->team->id,
            'title'     => 'VTT sync',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at'   => now()->toIso8601String(),
        ]);

        $response->assertCreated();
        $eventId = $response->json('data.calendar_event_id');

        $this->assertDatabaseHas('transcript_uploads', [
            'user_id'           => $this->user->id,
            'status'            => 'done',
            'calendar_event_id' => $eventId,
            'organization_id'   => $this->org->id,
        ]);

        $row = TranscriptUpload::where('calendar_event_id', $eventId)->first();
        $this->assertSame(2, $row->transcript_entries_count);
    }

    #[Test]
    public function transcript_parse_failure_records_failed_log_row_with_reason_and_event_id(): void
    {
        // WEBVTT header (signature-detected, no LLM) but no cues → deterministic parse failure.
        $file = UploadedFile::fake()->createWithContent('empty.vtt', "WEBVTT\n\nNOTE nothing here\n")->mimeType('text/vtt');

        $response = $this->actingAs($this->user)->post(route('transcripts.upload'), [
            'file'      => $file,
            'team_id'   => $this->team->id,
            'title'     => 'Doomed',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at'   => now()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.error_code', 'TRANSCRIPT_PARSE_FAILED');

        $row = TranscriptUpload::where('user_id', $this->user->id)->where('status', 'failed')->first();
        $this->assertNotNull($row);
        $this->assertSame('Could not parse transcript file', $row->error_message);
        // The synthetic event was created before the parse threw — its id is captured.
        $this->assertNotNull($row->calendar_event_id);
    }
}
