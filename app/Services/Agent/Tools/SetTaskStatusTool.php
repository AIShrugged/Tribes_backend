<?php

namespace App\Services\Agent\Tools;

use App\Enums\MeetingTaskStatus;
use App\Models\User;
use App\Services\Agent\Tools\Contracts\HighImpactAgentTool;
use App\Services\Commands\CommandAuthorizationException;
use App\Services\Commands\CommandRunner;
use App\Services\Commands\Issue\SetIssueStatusCommand;

/**
 * Agent-facing task-status mutation, routed through the audited, transactional,
 * reversible command layer (CommandRunner + SetIssueStatusCommand).
 *
 * Marked HighImpactAgentTool: in a tainted run (untrusted content was read), the
 * AgentService loop blocks it and requires human confirmation — closing the
 * lethal-trifecta loop for mutations, not just outbound messages.
 */
class SetTaskStatusTool extends AbstractAgentTool implements HighImpactAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly CommandRunner $runner,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'set_task_status';
    }

    public function getDescription(): string
    {
        return 'Изменить статус задачи (предпочтительный способ; аудируется и обратим, кроме reopen). '
            .'«Закрыть задачу» = status=done. Требует task_id и status.';
    }

    public function getParameters(): array
    {
        // reopen is excluded from this command (see SetIssueStatusCommand::authorize).
        $statuses = array_values(array_filter(
            array_map(static fn ($c) => $c->value, MeetingTaskStatus::cases()),
            static fn ($s) => $s !== MeetingTaskStatus::REOPEN->value,
        ));

        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer', 'description' => 'ID задачи.'],
                'status' => [
                    'type' => 'string',
                    'enum' => $statuses,
                    'description' => 'Новый статус. Допустимо: '.implode(', ', $statuses).'.',
                ],
            ],
            'required' => ['task_id', 'status'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $taskId = $parameters['task_id'] ?? null;
        $status = $parameters['status'] ?? null;

        if (! $taskId || ! is_string($status) || $status === '') {
            return ['success' => false, 'error' => 'task_id и status обязательны.'];
        }

        try {
            $result = $this->runner->run(new SetIssueStatusCommand((int) $taskId, $status), $this->user);
        } catch (CommandAuthorizationException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true] + $result->data;
    }
}
