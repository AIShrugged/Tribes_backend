<?php

namespace App\Services\SecondBrain;

use App\Enums\AgendaStatus;
use App\Enums\DecisionSourceType;
use App\Enums\FollowupStatus;
use App\Enums\MeetingTaskStatus;
use App\Exceptions\AppException;
use App\Models\BrainSuggestion;
use App\Models\CalendarEvent;
use App\Models\Decision;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\MeetingAgenda;
use App\Models\MeetingSummary;
use App\Models\Team;
use App\Models\User;
use App\Services\Decisions\DecisionAuthorResolver;
use App\Services\Issue\IssueAutoPipelineDispatcher;

/**
 * Applies an approved brain suggestion deterministically (no LLM/loop).
 *
 * This is the SINGLE adapter between a suggestion's stored "intent" payload and
 * the current data model. Field renames / model changes are handled HERE, so old
 * pending suggestions keep applying. Every handler RE-VALIDATES against the
 * current model at apply time and throws AppException on any mismatch — a stale
 * or invalid suggestion fails safe (never writes garbage).
 *
 * @return array<string, mixed> applied_result (e.g. ['issue_id' => 962])
 */
class SuggestionApplier
{
    public function apply(BrainSuggestion $suggestion, User $approver): array
    {
        if ((int) $suggestion->payload_version !== 1) {
            throw new AppException(
                "Unsupported suggestion payload_version {$suggestion->payload_version}.",
                'BRAIN_SUGGESTION_VERSION',
                422,
            );
        }

        $payload = is_array($suggestion->payload) ? $suggestion->payload : [];

        return match ($suggestion->key) {
            BrainSuggestion::KEY_CREATE_ISSUE => $this->applyCreateIssue($suggestion, $payload, $approver),
            BrainSuggestion::KEY_UPDATE_TASK_STATUS => $this->applyUpdateTaskStatus($suggestion, $payload),
            BrainSuggestion::KEY_ADD_COMMENT => $this->applyAddComment($suggestion, $payload, $approver),
            BrainSuggestion::KEY_SAVE_MEETING_SUMMARY => $this->applySaveMeetingSummary($suggestion, $payload),
            BrainSuggestion::KEY_SAVE_MEETING_AGENDA => $this->applySaveMeetingAgenda($suggestion, $payload),
            BrainSuggestion::KEY_SAVE_DECISION => $this->applySaveDecision($suggestion, $payload),
            default => throw new AppException("Unknown suggestion key '{$suggestion->key}'.", 'BRAIN_SUGGESTION_KEY', 422),
        };
    }

    /**
     * Resolve a meeting the approved suggestion may write to, scoped to the
     * suggestion's organization (the approver already proved they manage it).
     */
    private function assertMeetingInOrg(int $eventId, int $organizationId): CalendarEvent
    {
        if ($eventId <= 0) {
            $this->fail('calendar_event_id is required.');
        }

        $event = CalendarEvent::query()->whereKey($eventId)->visibleToOrganization($organizationId)->first();
        if (! $event) {
            $this->fail("meeting #{$eventId} not found in the organization.");
        }

        return $event;
    }

    /**
     * Persist a brain-generated meeting protocol/summary.
     *
     * Write-only: we deliberately do NOT dispatch MeetingSummaryGenerated. That
     * event's listener re-runs ExtractDecisionsService, which delete-then-inserts
     * decisions by summary_id — and would wipe the decisions the brain submits via
     * save_decision. The brain generates everything; the backend just persists.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applySaveMeetingSummary(BrainSuggestion $suggestion, array $payload): array
    {
        $organizationId = (int) $suggestion->organization_id;
        $event = $this->assertMeetingInOrg((int) ($payload['calendar_event_id'] ?? 0), $organizationId);

        $summaryText = trim((string) ($payload['summary'] ?? ''));
        if ($summaryText === '') {
            $this->fail('save_meeting_summary: summary is empty.');
        }

        $summary = $event->meetingSummary()->updateOrCreate([], [
            'status' => FollowupStatus::DONE->value,
            'title' => isset($payload['title']) ? (string) $payload['title'] : null,
            'summary' => $summaryText,
            'key_points' => $this->toArray($payload['key_points'] ?? null),
            'decisions' => $this->toArray($payload['decisions'] ?? null),
            'commitments' => $this->toArray($payload['commitments'] ?? null),
        ]);

        return ['calendar_event_id' => $event->id, 'summary_id' => $summary->id];
    }

    /**
     * Persist a brain-generated agenda for the NEXT meeting in the series.
     * General agenda only (v1); never overwrites an existing done agenda.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applySaveMeetingAgenda(BrainSuggestion $suggestion, array $payload): array
    {
        $organizationId = (int) $suggestion->organization_id;
        $event = $this->assertMeetingInOrg((int) ($payload['calendar_event_id'] ?? 0), $organizationId);

        $type = (string) ($payload['type'] ?? 'general');
        if ($type !== 'general') {
            $this->fail("save_meeting_agenda: only type 'general' is supported.");
        }

        $content = trim((string) ($payload['content'] ?? ''));
        if ($content === '') {
            $this->fail('save_meeting_agenda: content is empty.');
        }

        // Do not clobber an agenda the real pipeline already produced.
        $exists = MeetingAgenda::query()
            ->where('calendar_event_id', $event->id)
            ->where('type', 'general')
            ->whereNull('user_id')
            ->where('status', AgendaStatus::DONE->value)
            ->exists();
        if ($exists) {
            $this->fail("save_meeting_agenda: a general agenda already exists for meeting #{$event->id}.");
        }

        // Best-effort: the processed meeting must be org-visible and precede the target.
        $sourceMeetingId = (int) ($payload['source_meeting_id'] ?? 0);
        if ($sourceMeetingId > 0) {
            $source = $this->assertMeetingInOrg($sourceMeetingId, $organizationId);
            if ($source->starts_at !== null && $event->starts_at !== null && $source->starts_at >= $event->starts_at) {
                $this->fail('save_meeting_agenda: source_meeting_id must precede the target meeting.');
            }
        }

        $agenda = MeetingAgenda::create([
            'calendar_event_id' => $event->id,
            'user_id' => null,
            'type' => 'general',
            'status' => AgendaStatus::DONE->value,
            'raw_json' => $this->toArray($payload['raw_json'] ?? null),
            'content' => $content,
        ]);

        return ['calendar_event_id' => $event->id, 'agenda_id' => $agenda->id];
    }

    /**
     * Persist a single brain-generated decision (one row per proposal).
     *
     * @param  array<string, mixed>  $payload
     */
    private function applySaveDecision(BrainSuggestion $suggestion, array $payload): array
    {
        $organizationId = (int) $suggestion->organization_id;
        $event = $this->assertMeetingInOrg((int) ($payload['calendar_event_id'] ?? 0), $organizationId);

        $teamId = (int) ($payload['team_id'] ?? 0);
        if ($teamId <= 0) {
            $this->fail('save_decision: team_id is required.');
        }
        $team = Team::query()->find($teamId);
        if (! $team || (int) $team->organization_id !== $organizationId) {
            $this->fail('save_decision: team_id does not belong to the organization.');
        }

        $text = trim((string) ($payload['text'] ?? ''));
        if (mb_strlen($text) < 3) {
            $this->fail('save_decision: text is empty or too short.');
        }

        $resolved = app(DecisionAuthorResolver::class)->resolve($event, $payload['author_raw_name'] ?? null);

        // Opportunistic link if the summary was already approved this pass; nullable
        // by design. Safe because we never dispatch MeetingSummaryGenerated, so the
        // delete-then-insert in ExtractDecisionsService never runs against this row.
        $summaryId = MeetingSummary::query()->where('calendar_event_id', $event->id)->value('id');

        $decision = Decision::create([
            'calendar_event_id' => $event->id,
            'summary_id' => $summaryId,
            'team_id' => $team->id,
            'organization_id' => $organizationId,
            'source_type' => DecisionSourceType::Meeting->value,
            'author_user_id' => $resolved['user_id'],
            'author_profile_id' => $resolved['profile_id'],
            'author_raw_name' => $resolved['raw_name'],
            'text' => $text,
            'topic' => isset($payload['topic']) ? (string) $payload['topic'] : null,
        ]);

        return ['decision_id' => $decision->id, 'team_id' => $team->id];
    }

    /** @return array<int|string, mixed> */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $payload */
    private function applyAddComment(BrainSuggestion $suggestion, array $payload, User $approver): array
    {
        $organizationId = (int) $suggestion->organization_id;

        $issueId = (int) ($payload['issue_id'] ?? 0);
        $content = trim((string) ($payload['comment'] ?? ''));

        if ($issueId <= 0) {
            $this->fail('add_comment: issue_id is required.');
        }
        if ($content === '') {
            $this->fail('add_comment: comment is empty.');
        }

        $issue = Issue::query()->withoutTrashed()->inOrganization($organizationId)->find($issueId);
        if (! $issue) {
            $this->fail("add_comment: issue #{$issueId} not found in the organization.");
        }

        $comment = IssueComment::create([
            'issue_id' => $issue->id,
            'user_id' => $approver->id,
            'content' => $content,
        ]);

        return ['issue_id' => $issue->id, 'comment_id' => $comment->id];
    }

    /** @param array<string, mixed> $payload */
    private function applyCreateIssue(BrainSuggestion $suggestion, array $payload, User $approver): array
    {
        $organizationId = (int) $suggestion->organization_id;

        $name = trim((string) ($payload['name'] ?? ''));
        $type = trim((string) ($payload['type'] ?? ''));

        if ($name === '') {
            $this->fail('create_issue: name is empty.');
        }
        if (! in_array($type, Issue::TYPES, true)) {
            $this->fail("create_issue: type '{$type}' is not valid for the current Issue model.");
        }

        $status = (string) ($payload['status'] ?? MeetingTaskStatus::OPEN->value);
        if (! in_array($status, $this->validStatuses(), true)) {
            $this->fail("create_issue: status '{$status}' is invalid.");
        }

        $teamId = isset($payload['team_id']) && $payload['team_id'] !== null ? (int) $payload['team_id'] : null;
        if ($teamId !== null) {
            $team = Team::query()->find($teamId);
            if (! $team || (int) $team->organization_id !== $organizationId) {
                $this->fail('create_issue: team_id does not belong to the organization.');
            }
        }

        $assigneeId = isset($payload['assignee_id']) && $payload['assignee_id'] !== null ? (int) $payload['assignee_id'] : null;
        if ($assigneeId !== null) {
            $assignee = User::query()->find($assigneeId);
            if (! $assignee || ! $assignee->isOrganizationMember($organizationId)) {
                $this->fail('create_issue: assignee_id is not a member of the organization.');
            }
        }

        [$sourceableType, $sourceableId] = $this->resolveSource($payload);

        $issue = Issue::create([
            'user_id' => $approver->id,
            'organization_id' => $organizationId,
            'team_id' => $teamId,
            'assignee_id' => $assigneeId,
            'assignee_name' => $payload['assignee_name'] ?? null,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'type' => $type,
            'status' => $status,
            'due_date' => $payload['due_date'] ?? null,
            'sourceable_type' => $sourceableType,
            'sourceable_id' => $sourceableId,
        ]);

        app(IssueAutoPipelineDispatcher::class)->dispatchForStandalone([$issue->id]);

        return ['issue_id' => $issue->id, 'name' => $issue->name];
    }

    /** @param array<string, mixed> $payload */
    private function applyUpdateTaskStatus(BrainSuggestion $suggestion, array $payload): array
    {
        $organizationId = (int) $suggestion->organization_id;

        $issueId = (int) ($payload['issue_id'] ?? 0);
        $status = (string) ($payload['status'] ?? '');

        if ($issueId <= 0) {
            $this->fail('update_task_status: issue_id is required.');
        }
        if (! in_array($status, $this->validStatuses(), true)) {
            $this->fail("update_task_status: status '{$status}' is invalid.");
        }

        $issue = Issue::query()->withoutTrashed()->inOrganization($organizationId)->find($issueId);
        if (! $issue) {
            $this->fail("update_task_status: issue #{$issueId} not found in the organization.");
        }

        $old = $issue->status;
        $issue->update(['status' => $status]);

        return ['issue_id' => $issue->id, 'old_status' => $old, 'new_status' => $status];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?string, 1: ?int}
     */
    private function resolveSource(array $payload): array
    {
        $type = $payload['source_type'] ?? null;
        $id = $payload['source_id'] ?? null;

        if ($type === null || $id === null) {
            return [null, null];
        }

        $map = ['calendar_event' => CalendarEvent::class];
        if (! isset($map[$type])) {
            $this->fail("create_issue: unknown source_type '{$type}'.");
        }

        return [$map[$type], (int) $id];
    }

    /** @return array<int, string> */
    private function validStatuses(): array
    {
        return array_map(fn ($s) => $s->value, MeetingTaskStatus::cases());
    }

    private function fail(string $message): never
    {
        throw new AppException($message, 'BRAIN_SUGGESTION_INVALID', 422);
    }
}
