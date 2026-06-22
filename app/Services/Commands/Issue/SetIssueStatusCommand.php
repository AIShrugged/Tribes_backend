<?php

namespace App\Services\Commands\Issue;

use App\Enums\MeetingTaskStatus;
use App\Models\Issue;
use App\Models\User;
use App\Services\Commands\CommandAuthorizationException;
use App\Services\Commands\CommandInterface;
use App\Services\Commands\CommandResult;

/**
 * The Tier-1 issue mutation primitive: change a task's status.
 *
 * "Close a task" is this command with status=done; reopen/pause/etc. are the same
 * primitive with a different status. The mutation goes through the Eloquent model
 * so IssueObserver fires (status history, nudge invalidation, critical-path recompute).
 */
class SetIssueStatusCommand implements CommandInterface
{
    private ?Issue $issue = null;

    public function __construct(
        public readonly int $issueId,
        public readonly string $status,
    ) {}

    public function authorize(User $actor): void
    {
        $issue = Issue::query()->visibleTo($actor)->find($this->issueId);

        if ($issue === null) {
            // Same message for "not found" and "out of scope" — no cross-tenant probing.
            throw new CommandAuthorizationException("Задача #{$this->issueId} не найдена или нет доступа.");
        }

        $valid = array_map(static fn ($c) => $c->value, MeetingTaskStatus::cases());
        if (! in_array($this->status, $valid, true)) {
            throw new CommandAuthorizationException(
                "Недопустимый статус '{$this->status}'. Допустимо: ".implode(', ', $valid).'.'
            );
        }

        // reopen creates a downstream AgentTask and dispatches it (irreversible, fires
        // mid-transaction) — out of scope for the v1 command. Use the dedicated reopen flow.
        if ($this->status === MeetingTaskStatus::REOPEN->value) {
            throw new CommandAuthorizationException('Переоткрытие (reopen) пока не поддержано через этот инструмент.');
        }

        $this->issue = $issue;
    }

    public function execute(): CommandResult
    {
        $issue = $this->issue ?? throw new \LogicException('authorize() must run before execute()');

        $oldStatus = $issue->status;
        $issue->update(['status' => $this->status]); // Eloquent → IssueObserver fires.

        // reopen creates a downstream AgentTask (irreversible), so it cannot be undone.
        $invertible = $this->status !== MeetingTaskStatus::REOPEN->value;

        return new CommandResult(
            name: 'set_issue_status',
            targetType: 'issue',
            targetId: $issue->id,
            data: [
                'issue_id' => $issue->id,
                'name' => $issue->name,
                'old_status' => $oldStatus,
                'new_status' => $issue->status,
            ],
            snapshot: ['status' => $oldStatus],
            inversePayload: $invertible
                ? ['command' => 'set_issue_status', 'issue_id' => $issue->id, 'status' => $oldStatus]
                : null,
            summary: "Статус задачи #{$issue->id} изменён: {$oldStatus} → {$issue->status}",
        );
    }
}
