<?php

namespace App\Services\Agent\Tools;

use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Services\AgentTaskFollowupService;

class CreateFollowupAgentTaskTool extends AbstractAgentTool
{
    public function __construct(
        private readonly AgentTask $parentTask,
        private readonly AgentTaskRun $originRun,
        private readonly AgentTaskFollowupService $followupService,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'create_followup_agent_task';
    }

    public function getDescription(): string
    {
        return 'Create a small one-off follow-up agent task that inherits the parent task policy and can execute later without losing context.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['name', 'prompt'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Short title for the small follow-up task.',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Instruction for the follow-up task. Keep it narrow and executable.',
                ],
                'context_summary' => [
                    'type' => 'string',
                    'description' => 'Compact handoff context for the next task so it can continue without rereading everything.',
                ],
                'delay_seconds' => [
                    'type' => 'integer',
                    'description' => 'Optional delay before the follow-up task becomes due.',
                ],
                'execution_mode' => [
                    'type' => 'string',
                    'enum' => ['inline', 'isolated'],
                    'description' => 'Optional execution mode override for the follow-up task.',
                ],
                'sandbox_profile' => [
                    'type' => 'string',
                    'description' => 'Optional sandbox profile override.',
                ],
                'allowed_tools' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional subset of the parent task tool allowlist.',
                ],
                'input_payload' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'description' => 'Optional structured payload for the follow-up task. Defaults to the parent task payload.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        try {
            $task = $this->followupService->createFollowupTask(
                $this->parentTask,
                $this->originRun,
                $parameters ?? [],
            );
        } catch (\RuntimeException $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'success' => true,
            'agent_task' => [
                'id' => $task->id,
                'name' => $task->name,
                'parent_agent_task_id' => $task->parent_agent_task_id,
                'origin_agent_task_run_id' => $task->origin_agent_task_run_id,
                'followup_depth' => $task->followup_depth,
                'next_run_at' => $task->next_run_at?->toIso8601String(),
                'execution_mode' => $task->execution_mode,
            ],
        ];
    }
}
