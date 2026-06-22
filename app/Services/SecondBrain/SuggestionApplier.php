<?php

namespace App\Services\SecondBrain;

use App\Enums\MeetingTaskStatus;
use App\Exceptions\AppException;
use App\Models\BrainSuggestion;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\Team;
use App\Models\User;
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
            default => throw new AppException("Unknown suggestion key '{$suggestion->key}'.", 'BRAIN_SUGGESTION_KEY', 422),
        };
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
     * @param array<string, mixed> $payload
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
