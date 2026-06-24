<?php

namespace App\Services\Commands\Issue;

use App\Models\Issue;
use App\Models\User;
use App\Services\Commands\CommandAuthorizationException;
use App\Services\Commands\CommandInterface;
use App\Services\Commands\CommandResult;

/**
 * Tier-1 primitive: update plain scalar fields of a task (name, description,
 * priority, due_date). Status / assignee / tenant fields are handled by dedicated
 * commands (SetIssueStatus / ReassignIssue) — they have their own side effects and
 * scoping, so they are NOT updatable here.
 */
class UpdateIssueFieldsCommand implements CommandInterface
{
    private const ALLOWED = ['name', 'description', 'priority', 'due_date'];

    private ?Issue $issue = null;

    /** @var array<string, mixed> */
    private array $clean = [];

    /** @param  array<string, mixed>  $fields */
    public function __construct(
        public readonly int $issueId,
        public readonly array $fields,
    ) {}

    public function authorize(User $actor): void
    {
        $issue = Issue::query()->visibleTo($actor)->find($this->issueId);

        if ($issue === null) {
            throw new CommandAuthorizationException("Задача #{$this->issueId} не найдена или нет доступа.");
        }

        $clean = array_intersect_key($this->fields, array_flip(self::ALLOWED));

        if ($clean === []) {
            throw new CommandAuthorizationException(
                'Нет допустимых полей для обновления. Допустимо: '.implode(', ', self::ALLOWED).'.'
            );
        }

        if (array_key_exists('priority', $clean) && ! is_numeric($clean['priority'])) {
            throw new CommandAuthorizationException('Поле priority должно быть числом.');
        }

        $this->issue = $issue;
        $this->clean = $clean;
    }

    public function execute(): CommandResult
    {
        $issue = $this->issue ?? throw new \LogicException('authorize() must run before execute()');

        $old = $issue->only(array_keys($this->clean));
        $issue->update($this->clean); // Eloquent → observer fires (CPM on priority/due_date/name).

        return new CommandResult(
            name: 'update_issue_fields',
            targetType: 'issue',
            targetId: $issue->id,
            data: ['issue_id' => $issue->id, 'updated' => array_keys($this->clean)],
            snapshot: $old,
            inversePayload: ['command' => 'update_issue_fields', 'issue_id' => $issue->id, 'fields' => $old],
            summary: "Обновлены поля задачи #{$issue->id}: ".implode(', ', array_keys($this->clean)),
        );
    }
}
