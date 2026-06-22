<?php

namespace App\Services\Commands\Issue;

use App\Models\Issue;
use App\Models\User;
use App\Services\Commands\CommandAuthorizationException;
use App\Services\Commands\CommandInterface;
use App\Services\Commands\CommandResult;

/**
 * Tier-1 primitive: change a task's assignee (or unassign with null).
 *
 * The new assignee must share an organization with the acting user (or be the
 * actor themselves) — you cannot assign work to someone outside your tenant.
 */
class ReassignIssueCommand implements CommandInterface
{
    private ?Issue $issue = null;

    public function __construct(
        public readonly int $issueId,
        public readonly ?int $assigneeId,
    ) {}

    public function authorize(User $actor): void
    {
        $issue = Issue::query()->visibleTo($actor)->find($this->issueId);

        if ($issue === null) {
            throw new CommandAuthorizationException("Задача #{$this->issueId} не найдена или нет доступа.");
        }

        if ($this->assigneeId !== null && $this->assigneeId !== $actor->id) {
            $orgIds = $actor->organizations()->pluck('organizations.id');
            $accessible = User::query()
                ->whereKey($this->assigneeId)
                ->whereHas('organizations', fn ($q) => $q->whereIn('organizations.id', $orgIds))
                ->exists();

            if (! $accessible) {
                throw new CommandAuthorizationException(
                    "Пользователь #{$this->assigneeId} недоступен (не состоит в ваших организациях) — нельзя назначить."
                );
            }
        }

        $this->issue = $issue;
    }

    public function execute(): CommandResult
    {
        $issue = $this->issue ?? throw new \LogicException('authorize() must run before execute()');

        $oldAssignee = $issue->assignee_id;
        $issue->update(['assignee_id' => $this->assigneeId]); // Eloquent → observer fires.

        return new CommandResult(
            name: 'reassign_issue',
            targetType: 'issue',
            targetId: $issue->id,
            data: [
                'issue_id' => $issue->id,
                'name' => $issue->name,
                'old_assignee_id' => $oldAssignee,
                'new_assignee_id' => $this->assigneeId,
            ],
            snapshot: ['assignee_id' => $oldAssignee],
            inversePayload: ['command' => 'reassign_issue', 'issue_id' => $issue->id, 'assignee_id' => $oldAssignee],
            summary: "Исполнитель задачи #{$issue->id}: ".($oldAssignee ?? '—').' → '.($this->assigneeId ?? '—'),
        );
    }
}
