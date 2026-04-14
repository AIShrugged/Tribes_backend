<?php

namespace App\Services\Agent\Tools;

use App\Models\AgentTask;
use App\Models\User;
use App\Services\AgentTaskMutationService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateAgentTaskTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly AgentTaskMutationService $taskMutationService,
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'update_agent_task';
    }

    public function getDescription(): string
    {
        return 'Update parameters of an existing agent task. Only organization managers can update tasks, and updates must stay within the current tenant scope.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'required' => ['agent_task_id'],
            'properties' => [
                'agent_task_id' => [
                    'type' => 'integer',
                    'description' => 'Agent task id to update.',
                ],
                'name' => ['type' => 'string'],
                'prompt' => ['type' => 'string'],
                'organization_id' => ['type' => 'integer'],
                'team_id' => ['type' => ['integer', 'null']],
                'agent_profile_id' => ['type' => ['integer', 'null']],
                'schedule_type' => ['type' => 'string', 'enum' => ['one_off', 'interval']],
                'execution_mode' => ['type' => ['string', 'null'], 'enum' => ['inline', 'isolated', 'paperclip', null]],
                'sandbox_profile' => ['type' => ['string', 'null']],
                'interval_seconds' => ['type' => ['integer', 'null']],
                'agent_task_type' => ['type' => ['string', 'null']],
                'output_mode' => ['type' => ['string', 'null']],
                'allowed_tools' => [
                    'type' => ['array', 'null'],
                    'items' => ['type' => 'string'],
                ],
                'allowed_outbound_hosts' => [
                    'type' => ['array', 'null'],
                    'items' => ['type' => 'string'],
                ],
                'input_payload' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => true,
                ],
                'next_run_at' => ['type' => ['string', 'null']],
                'enabled' => ['type' => ['boolean', 'null']],
                'max_attempts' => ['type' => ['integer', 'null']],
                'metadata' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => true,
                ],
                'idempotency_key' => [
                    'type' => 'string',
                    'description' => 'Optional explicit idempotency key for the update request.',
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $task = AgentTask::query()->find((int) ($parameters['agent_task_id'] ?? 0));

        if (! $task) {
            return ['success' => false, 'error' => 'Agent task not found or access denied'];
        }

        if (! $this->user->isOrganizationMember($task->organization_id)) {
            return ['success' => false, 'error' => 'Agent task not found or access denied'];
        }

        $updateData = $this->extractUpdateData($parameters);
        if ($updateData === []) {
            return ['success' => false, 'error' => 'No updatable fields were provided'];
        }

        try {
            $this->validateUpdateData($updateData);
            $this->assertTaskMatchesScope($task->organization_id, $task->team_id);

            $effectiveOrganizationId = array_key_exists('organization_id', $updateData)
                ? ($updateData['organization_id'] !== null ? (int) $updateData['organization_id'] : null)
                : $task->organization_id;
            $effectiveTeamId = array_key_exists('team_id', $updateData)
                ? ($updateData['team_id'] !== null ? (int) $updateData['team_id'] : null)
                : $task->team_id;

            $this->assertTaskMatchesScope($effectiveOrganizationId, $effectiveTeamId);

            $persisted = $this->taskMutationService->preparePersistedData($updateData, $this->user, $task);
        } catch (ValidationException $exception) {
            return [
                'success' => false,
                'error' => 'Agent task validation failed.',
                'details' => $exception->errors(),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $task->update($persisted);
        $task->refresh();

        return [
            'success' => true,
            'agent_task' => [
                'id' => $task->id,
                'name' => $task->name,
                'organization_id' => $task->organization_id,
                'team_id' => $task->team_id,
                'agent_profile_id' => $task->agent_profile_id,
                'schedule_type' => $task->schedule_type?->value ?? $task->schedule_type,
                'interval_seconds' => $task->interval_seconds,
                'execution_mode' => $task->execution_mode?->value ?? $task->execution_mode,
                'next_run_at' => $task->next_run_at?->toIso8601String(),
                'enabled' => (bool) $task->enabled,
                'max_attempts' => (int) $task->max_attempts,
            ],
        ];
    }

    private function extractUpdateData(array $parameters): array
    {
        $fields = [
            'name',
            'prompt',
            'organization_id',
            'team_id',
            'agent_profile_id',
            'schedule_type',
            'execution_mode',
            'sandbox_profile',
            'interval_seconds',
            'agent_task_type',
            'output_mode',
            'allowed_tools',
            'allowed_outbound_hosts',
            'input_payload',
            'next_run_at',
            'enabled',
            'max_attempts',
            'metadata',
        ];

        $data = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $parameters)) {
                $data[$field] = $parameters[$field];
            }
        }

        return $data;
    }

    private function validateUpdateData(array $data): void
    {
        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'prompt' => ['sometimes', 'string'],
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'agent_profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
            'schedule_type' => ['sometimes', 'in:one_off,interval'],
            'execution_mode' => ['nullable', 'in:inline,isolated'],
            'sandbox_profile' => ['nullable', 'string', 'max:64'],
            'interval_seconds' => ['nullable', 'integer', 'min:1'],
            'agent_task_type' => ['nullable', 'string', 'max:32'],
            'output_mode' => ['nullable', 'string', 'max:16'],
            'allowed_tools' => ['nullable', 'array'],
            'allowed_tools.*' => ['string', 'max:255'],
            'allowed_outbound_hosts' => ['nullable', 'array'],
            'allowed_outbound_hosts.*' => ['string', 'max:255'],
            'input_payload' => ['nullable', 'array'],
            'next_run_at' => ['nullable', 'date'],
            'enabled' => ['nullable', 'boolean'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function assertTaskMatchesScope(?int $organizationId, ?int $teamId): void
    {
        if ($this->organizationId !== null && $organizationId !== $this->organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => ['Agent task updates are restricted to the current organization scope.'],
            ]);
        }

        if ($this->teamId !== null && $teamId !== $this->teamId) {
            throw ValidationException::withMessages([
                'team_id' => ['Agent task updates are restricted to the current team scope.'],
            ]);
        }
    }
}
