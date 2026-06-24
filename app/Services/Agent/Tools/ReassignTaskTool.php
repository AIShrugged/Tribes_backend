<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\Agent\Tools\Contracts\HighImpactAgentTool;
use App\Services\Commands\CommandAuthorizationException;
use App\Services\Commands\CommandRunner;
use App\Services\Commands\Issue\ReassignIssueCommand;

/**
 * Agent-facing task reassignment, routed through the audited command layer.
 * High-impact → taint-gated.
 */
class ReassignTaskTool extends AbstractAgentTool implements HighImpactAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly CommandRunner $runner,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'reassign_task';
    }

    public function getDescription(): string
    {
        return 'Назначить задачу на пользователя (или снять исполнителя, передав assignee_id=null). '
            .'Новый исполнитель должен состоять в твоих организациях. Аудируется и обратимо.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer', 'description' => 'ID задачи.'],
                'assignee_id' => ['type' => 'integer', 'description' => 'ID нового исполнителя; null — снять исполнителя.'],
            ],
            'required' => ['task_id'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $taskId = $parameters['task_id'] ?? null;

        if (! $taskId) {
            return ['success' => false, 'error' => 'task_id обязателен.'];
        }

        $assigneeId = array_key_exists('assignee_id', $parameters) && $parameters['assignee_id'] !== null
            ? (int) $parameters['assignee_id']
            : null;

        try {
            $result = $this->runner->run(new ReassignIssueCommand((int) $taskId, $assigneeId), $this->user);
        } catch (CommandAuthorizationException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true] + $result->data;
    }
}
