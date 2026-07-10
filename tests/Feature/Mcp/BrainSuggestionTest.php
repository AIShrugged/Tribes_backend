<?php

namespace Tests\Feature\Mcp;

use App\Enums\AgendaStatus;
use App\Events\MeetingSummaryGenerated;
use App\Models\BrainSuggestion;
use App\Models\CalendarEvent;
use App\Models\Decision;
use App\Models\Issue;
use App\Models\MeetingAgenda;
use App\Models\MeetingSummary;
use App\Models\Methodology;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TranscriptUpload;
use App\Models\User;
use App\Services\Agent\Tools\SuggestActionTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The human-in-the-loop suggestion flow: the brain proposes via suggest_action
 * (no direct mutation); a manager approves → the backend applies deterministically.
 */
class BrainSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Queue::fake(); // keep the issue auto-pipeline from running in tests
    }

    #[Test]
    public function suggest_action_creates_a_pending_suggestion_scoped_to_org(): void
    {
        [$user, $org] = $this->managerFor('A');
        $this->actingAs($user);

        $res = (new SuggestActionTool)->execute([
            'key' => 'create_issue',
            'title' => '[BRAIN] Add missing task',
            'dedupe_key' => 'lost:decision:1:x',
            'reasoning' => 'Decided but never tracked',
            'payload' => ['name' => '[BRAIN] Add missing task', 'type' => 'organization'],
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame(1, BrainSuggestion::where('organization_id', $org->id)->pending()->count());
    }

    #[Test]
    public function suggest_action_is_idempotent_by_dedupe_key(): void
    {
        [$user] = $this->managerFor('A');
        $this->actingAs($user);
        $tool = new SuggestActionTool;

        $tool->execute(['key' => 'create_issue', 'title' => 'v1', 'dedupe_key' => 'k1', 'payload' => ['name' => 'v1', 'type' => 'organization']]);
        $tool->execute(['key' => 'create_issue', 'title' => 'v2', 'dedupe_key' => 'k1', 'payload' => ['name' => 'v2', 'type' => 'organization']]);

        $this->assertSame(1, BrainSuggestion::where('dedupe_key', 'k1')->count());
        $this->assertSame('v2', BrainSuggestion::where('dedupe_key', 'k1')->first()->title);
    }

    #[Test]
    public function approving_a_create_issue_suggestion_creates_the_issue(): void
    {
        [$user, $org] = $this->managerFor('A');
        $suggestion = $this->suggestion($org, 'create_issue', ['name' => '[BRAIN] Fix dashboard counts', 'type' => 'organization']);

        Sanctum::actingAs($user, ['*']);
        $response = $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve");

        $response->assertOk();
        $suggestion->refresh();
        $this->assertSame(BrainSuggestion::STATUS_APPLIED, $suggestion->status);
        $issueId = $suggestion->applied_result['issue_id'] ?? null;
        $this->assertNotNull($issueId);
        $this->assertDatabaseHas('issues', ['id' => $issueId, 'organization_id' => $org->id, 'name' => '[BRAIN] Fix dashboard counts']);
    }

    #[Test]
    public function approving_an_invalid_suggestion_fails_safe_without_creating_anything(): void
    {
        [$user, $org] = $this->managerFor('A');
        $suggestion = $this->suggestion($org, 'create_issue', ['name' => 'x', 'type' => 'NOT_A_REAL_TYPE']);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertStatus(422);

        $this->assertSame(BrainSuggestion::STATUS_FAILED, $suggestion->fresh()->status);
        $this->assertSame(0, Issue::count());
    }

    #[Test]
    public function approving_an_update_task_status_suggestion_changes_the_issue(): void
    {
        [$user, $org] = $this->managerFor('A');
        $issue = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Stalled task',
            'type' => Issue::TYPE_ORGANIZATION,
            'status' => 'open',
        ]);
        $suggestion = $this->suggestion($org, 'update_task_status', ['issue_id' => $issue->id, 'status' => 'done']);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertOk();

        $this->assertSame('done', $issue->fresh()->status);
    }

    #[Test]
    public function approving_an_add_comment_suggestion_adds_a_comment_to_the_issue(): void
    {
        [$user, $org] = $this->managerFor('A');
        $issue = Issue::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Existing task',
            'type' => Issue::TYPE_ORGANIZATION,
            'status' => 'open',
        ]);
        $suggestion = $this->suggestion($org, 'add_comment', [
            'issue_id' => $issue->id,
            'comment' => 'На встрече 12.06 договорились добавить сюда премодерацию.',
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertOk();

        $this->assertDatabaseHas('issue_comments', [
            'issue_id' => $issue->id,
            'user_id' => $user->id,
            'content' => 'На встрече 12.06 договорились добавить сюда премодерацию.',
        ]);
        $this->assertSame(BrainSuggestion::STATUS_APPLIED, $suggestion->fresh()->status);
    }

    #[Test]
    public function approving_an_add_comment_for_a_foreign_issue_fails_safe(): void
    {
        [$userA, $orgA] = $this->managerFor('A');
        [$userB, $orgB] = $this->managerFor('B');
        $foreignIssue = Issue::create([
            'user_id' => $userB->id,
            'organization_id' => $orgB->id,
            'name' => 'Other org task',
            'type' => Issue::TYPE_ORGANIZATION,
            'status' => 'open',
        ]);
        // A suggestion in org A that points at org B's issue must not write across tenants.
        $suggestion = $this->suggestion($orgA, 'add_comment', ['issue_id' => $foreignIssue->id, 'comment' => 'leak?']);

        Sanctum::actingAs($userA, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertStatus(422);

        $this->assertDatabaseMissing('issue_comments', ['issue_id' => $foreignIssue->id, 'content' => 'leak?']);
        $this->assertSame(BrainSuggestion::STATUS_FAILED, $suggestion->fresh()->status);
    }

    #[Test]
    public function a_manager_cannot_approve_another_organizations_suggestion(): void
    {
        [$userA] = $this->managerFor('A');
        [, $orgB] = $this->managerFor('B');
        $suggestion = $this->suggestion($orgB, 'create_issue', ['name' => 'x', 'type' => 'organization']);

        Sanctum::actingAs($userA, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertStatus(403);
        $this->assertSame(BrainSuggestion::STATUS_PENDING, $suggestion->fresh()->status);
    }

    #[Test]
    public function a_rejected_suggestion_is_not_resurrected_by_re_proposing(): void
    {
        [$user, $org] = $this->managerFor('A');
        $suggestion = $this->suggestion($org, 'create_issue', ['name' => 'x', 'type' => 'organization'], 'dup-key');

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/reject")->assertOk();

        // Brain re-proposes the same dedupe_key.
        $this->actingAs($user);
        $res = (new SuggestActionTool)->execute(['key' => 'create_issue', 'title' => 'again', 'dedupe_key' => 'dup-key', 'payload' => ['name' => 'x', 'type' => 'organization']]);

        $this->assertTrue($res['success']);
        $this->assertSame('rejected', $res['status']);
        $this->assertSame(0, BrainSuggestion::where('organization_id', $org->id)->pending()->count());
    }

    // --- Brain-generated post-meeting artifacts (save_meeting_summary/agenda/decision) ---

    #[Test]
    public function approving_save_meeting_summary_writes_the_summary_without_re_dispatching_the_pipeline(): void
    {
        [$user, $org] = $this->managerFor('A');
        $meeting = $this->makeMeeting($org, $user);
        $suggestion = $this->suggestion($org, 'save_meeting_summary', [
            'calendar_event_id' => $meeting->id,
            'title' => 'Планёрка 12.06',
            'summary' => '## Протокол\nОбсудили миграцию.',
            'key_points' => ['Точка А', 'Точка Б'],
            'decisions' => ['Перейти на API-доступ'],
            'commitments' => [['who' => 'Борис', 'what' => 'Схема БД', 'deadline' => '2026-06-20']],
        ]);

        // Write-only: the summary event must NOT be re-dispatched (it would let the
        // backend re-extract & delete the brain's decisions).
        Event::fake([MeetingSummaryGenerated::class]);
        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertOk();
        Event::assertNotDispatched(MeetingSummaryGenerated::class);

        $this->assertSame(BrainSuggestion::STATUS_APPLIED, $suggestion->fresh()->status);
        $summary = MeetingSummary::where('calendar_event_id', $meeting->id)->firstOrFail();
        $this->assertSame('done', $summary->status);
        $this->assertSame(['Точка А', 'Точка Б'], $summary->key_points);
        $this->assertSame(['Перейти на API-доступ'], $summary->decisions);
        $this->assertSame('Борис', $summary->commitments[0]['who']);
    }

    #[Test]
    public function approving_save_meeting_summary_for_a_foreign_meeting_fails_safe(): void
    {
        [$userA, $orgA] = $this->managerFor('A');
        [$userB, $orgB] = $this->managerFor('B');
        $foreignMeeting = $this->makeMeeting($orgB, $userB);
        $suggestion = $this->suggestion($orgA, 'save_meeting_summary', [
            'calendar_event_id' => $foreignMeeting->id,
            'summary' => 'leak?',
        ]);

        Sanctum::actingAs($userA, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertStatus(422);

        $this->assertSame(BrainSuggestion::STATUS_FAILED, $suggestion->fresh()->status);
        $this->assertSame(0, MeetingSummary::count());
    }

    #[Test]
    public function approving_save_meeting_agenda_creates_a_general_agenda(): void
    {
        [$user, $org] = $this->managerFor('A');
        $nextMeeting = $this->makeMeeting($org, $user);
        $suggestion = $this->suggestion($org, 'save_meeting_agenda', [
            'calendar_event_id' => $nextMeeting->id,
            'type' => 'general',
            'content' => '## Повестка',
            'raw_json' => ['meeting_goal' => 'Синк', 'discussion_topics' => [['title' => 'T', 'description' => 'D']]],
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertOk();

        $agenda = MeetingAgenda::where('calendar_event_id', $nextMeeting->id)->firstOrFail();
        $this->assertSame('general', $agenda->type);
        $this->assertNull($agenda->user_id);
        $this->assertSame(AgendaStatus::DONE->value, $agenda->status instanceof \BackedEnum ? $agenda->status->value : $agenda->status);
        $this->assertSame('Синк', $agenda->raw_json['meeting_goal']);
    }

    #[Test]
    public function save_meeting_agenda_overwrites_an_existing_general_agenda(): void
    {
        // The brain is an independent pipeline running alongside the code one:
        // an approved agenda overwrites the code pipeline's general agenda.
        [$user, $org] = $this->managerFor('A');
        $nextMeeting = $this->makeMeeting($org, $user);
        MeetingAgenda::create([
            'calendar_event_id' => $nextMeeting->id,
            'user_id' => null,
            'type' => 'general',
            'status' => AgendaStatus::DONE->value,
            'raw_json' => ['meeting_goal' => 'original'],
            'content' => 'original',
        ]);
        $suggestion = $this->suggestion($org, 'save_meeting_agenda', [
            'calendar_event_id' => $nextMeeting->id,
            'type' => 'general',
            'content' => 'brain override',
            'raw_json' => ['meeting_goal' => 'overridden'],
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertOk();

        $this->assertSame(BrainSuggestion::STATUS_APPLIED, $suggestion->fresh()->status);
        $this->assertSame(1, MeetingAgenda::where('calendar_event_id', $nextMeeting->id)->count());
        $agenda = MeetingAgenda::where('calendar_event_id', $nextMeeting->id)->first();
        $this->assertSame('brain override', $agenda->content);
        $this->assertSame('overridden', $agenda->raw_json['meeting_goal']);
    }

    #[Test]
    public function approving_save_decision_writes_a_decision(): void
    {
        [$user, $org] = $this->managerFor('A');
        $meeting = $this->makeMeeting($org, $user);
        $team = $this->makeTeam($org);
        $suggestion = $this->suggestion($org, 'save_decision', [
            'calendar_event_id' => $meeting->id,
            'team_id' => $team->id,
            'text' => 'Доступ к БД — только через API',
            'topic' => 'Архитектура',
            'author_raw_name' => 'Фёдор',
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertOk();

        $this->assertDatabaseHas('decisions', [
            'calendar_event_id' => $meeting->id,
            'team_id' => $team->id,
            'organization_id' => $org->id,
            'source_type' => 'meeting',
            'author_raw_name' => 'Фёдор',
            'text' => 'Доступ к БД — только через API',
        ]);
    }

    #[Test]
    public function save_decision_for_a_foreign_team_fails_safe(): void
    {
        [$userA, $orgA] = $this->managerFor('A');
        [, $orgB] = $this->managerFor('B');
        $meeting = $this->makeMeeting($orgA, $userA);
        $foreignTeam = $this->makeTeam($orgB);
        $suggestion = $this->suggestion($orgA, 'save_decision', [
            'calendar_event_id' => $meeting->id,
            'team_id' => $foreignTeam->id,
            'text' => 'leak?',
        ]);

        Sanctum::actingAs($userA, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertStatus(422);

        $this->assertSame(BrainSuggestion::STATUS_FAILED, $suggestion->fresh()->status);
        $this->assertSame(0, Decision::count());
    }

    #[Test]
    public function approving_create_issue_from_a_meeting_links_the_calendar_event_source(): void
    {
        [$user, $org] = $this->managerFor('A');
        $meeting = $this->makeMeeting($org, $user);
        $suggestion = $this->suggestion($org, 'create_issue', [
            'name' => '[BRAIN] Добавить премодерацию',
            'type' => 'organization',
            'source_type' => 'calendar_event',
            'source_id' => $meeting->id,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/brain/suggestions/{$suggestion->id}/approve")->assertOk();

        $issueId = $suggestion->fresh()->applied_result['issue_id'] ?? null;
        $this->assertDatabaseHas('issues', [
            'id' => $issueId,
            'sourceable_type' => CalendarEvent::class,
            'sourceable_id' => $meeting->id,
        ]);
    }

    private function makeMeeting(Organization $org, User $user): CalendarEvent
    {
        $event = CalendarEvent::create([
            'title' => 'Meeting '.uniqid(),
            'platform' => 'test',
            'url' => 'https://meet.test/'.uniqid(),
            'description' => 'test meeting',
            'starts_at' => now()->subHour(),
            'ends_at' => now(),
        ]);

        TranscriptUpload::create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'calendar_event_id' => $event->id,
            'original_filename' => 'transcript.txt',
            'transcript_entries_count' => 1,
            'participants_count' => 1,
        ]);

        return $event;
    }

    private function makeTeam(Organization $org): Team
    {
        $methodology = Methodology::create([
            'name' => 'M '.uniqid(),
            'text' => 'x',
            'scheme' => json_encode(['type' => 'object']),
            'organization_id' => $org->id,
        ]);

        return Team::create([
            'name' => 'Team '.uniqid(),
            'slug' => 'team-'.uniqid(),
            'organization_id' => $org->id,
            'methodology_id' => $methodology->id,
        ]);
    }

    private function suggestion(Organization $org, string $key, array $payload, string $dedupe = 'k'): BrainSuggestion
    {
        return BrainSuggestion::create([
            'organization_id' => $org->id,
            'key' => $key,
            'payload_version' => 1,
            'payload' => $payload,
            'title' => 'proposal',
            'dedupe_key' => $dedupe,
            'status' => BrainSuggestion::STATUS_PENDING,
        ]);
    }

    /** @return array{0: User, 1: Organization} */
    private function managerFor(string $suffix): array
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => 'org-'.strtolower($suffix).'-'.uniqid()]);
        $org->users()->attach($user->id, ['role' => 'manager']);

        return [$user, $org];
    }
}
