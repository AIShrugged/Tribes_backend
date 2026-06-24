<?php

namespace App\Services\Agent\Tools;

use App\Models\User;
use App\Services\Agent\Tools\Contracts\HighImpactAgentTool;
use App\Services\Commands\CommandAuthorizationException;
use App\Services\Commands\CommandRunner;
use App\Services\Commands\Issue\UpdateIssueFieldsCommand;

/**
 * Agent-facing update of a task's plain fields (name, description, priority,
 * due_date), routed through the audited command layer. Status and assignee have
 * dedicated tools (set_task_status, reassign_task). High-impact → taint-gated.
 */
class UpdateTaskFieldsTool extends AbstractAgentTool implements HighImpactAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly CommandRunner $runner,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'update_task_fields';
    }

    public function getDescription(): string
    {
        return 'Изменить поля задачи: name, description, priority (число: 500=critical,100=high,0=normal,-100=low), '
            .'due_date (YYYY-MM-DD). Статус и исполнителя меняй через set_task_status / reassign_task. Аудируется и обратимо.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => ['type' => 'integer', 'description' => 'ID задачи.'],
                'name' => ['type' => 'string', 'description' => 'Новое название.'],
                'description' => ['type' => 'string', 'description' => 'Новое описание / ТЗ.'],
                'priority' => ['type' => 'integer', 'description' => 'Приоритет (число).'],
                'due_date' => ['type' => 'string', 'description' => 'Срок YYYY-MM-DD.'],
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

        $fields = array_intersect_key($parameters, array_flip(['name', 'description', 'priority', 'due_date']));

        try {
            $result = $this->runner->run(new UpdateIssueFieldsCommand((int) $taskId, $fields), $this->user);
        } catch (CommandAuthorizationException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true] + $result->data;
    }
}
