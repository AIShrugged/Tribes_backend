<?php

namespace App\Services\Agent\Tools;

use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\User;
use App\Services\JsonSchemaValidationService;
use App\Services\TenantScopeValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateAgentTaskTool extends AbstractAgentTool
{
    public function __construct(
        private readonly ?User $user = null,
        private readonly ?JsonSchemaValidationService $schemaValidation = null,
        private readonly ?TenantScopeValidator $tenantScopeValidator = null,
        private readonly ?int $defaultOrganizationId = null,
        private readonly ?int $defaultTeamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'create_agent_task';
    }

    public function getDescription(): string
    {
        return 'Create a background task for another agent run. Use it when the work should be delegated to the agent-task system rather than assigned to a human as an issue.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Short agent task name.',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Instruction the background agent should execute.',
                ],
                'organization_id' => [
                    'type' => 'integer',
                    'description' => 'Organization id. Required unless the current agent context already has an organization scope.',
                ],
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional team id within the selected organization.',
                ],
                'agent_profile_id' => [
                    'type' => 'integer',
                    'description' => 'Optional agent profile id.',
                ],
                'schedule_type' => [
                    'type' => 'string',
                    'enum' => ['one_off', 'interval'],
                    'description' => 'Scheduling mode. Defaults to one_off.',
                ],
                'interval_seconds' => [
                    'type' => 'integer',
                    'description' => 'Interval in seconds when schedule_type is interval.',
                ],
                'next_run_at' => [
                    'type' => 'string',
                    'description' => 'Optional ISO-8601 datetime for the first run. Defaults to now.',
                ],
                'execution_mode' => [
                    'type' => 'string',
                    'enum' => ['inline', 'isolated'],
                    'description' => 'Optional execution mode override.',
                ],
                'sandbox_profile' => [
                    'type' => 'string',
                    'description' => 'Optional sandbox profile override.',
                ],
                'agent_task_type' => [
                    'type' => 'string',
                    'description' => 'Optional task type. Defaults to background.',
                ],
                'output_mode' => [
                    'type' => 'string',
                    'description' => 'Optional output mode. Defaults to plain.',
                ],
                'allowed_tools' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                    'description' => 'Optional tool allowlist for the delegated agent task.',
                ],
                'allowed_outbound_hosts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                    'description' => 'Optional outbound host allowlist for the delegated agent task.',
                ],
                'input_payload' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'description' => 'Optional structured payload for the delegated task.',
                ],
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Whether the delegated task is enabled. Defaults to true.',
                ],
                'max_attempts' => [
                    'type' => 'integer',
                    'description' => 'Maximum queue attempts. Defaults to 3.',
                ],
                'metadata' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'description' => 'Optional task metadata.',
                ],
            ],
            'required' => ['name', 'prompt'],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $user = $this->resolveCurrentUser();

        if (! $user) {
            return ['success' => false, 'error' => 'Authenticated user context is required'];
        }

        $name = trim((string) ($parameters['name'] ?? ''));
        $prompt = trim((string) ($parameters['prompt'] ?? ''));

        if ($name === '') {
            return ['success' => false, 'error' => 'name is required'];
        }

        if ($prompt === '') {
            return ['success' => false, 'error' => 'prompt is required'];
        }

        $organizationId = isset($parameters['organization_id'])
            ? (int) $parameters['organization_id']
            : $this->defaultOrganizationId;
        $teamId = array_key_exists('team_id', $parameters)
            ? ($parameters['team_id'] !== null ? (int) $parameters['team_id'] : null)
            : $this->defaultTeamId;
        $scheduleType = (string) ($parameters['schedule_type'] ?? 'one_off');
        $intervalSeconds = isset($parameters['interval_seconds']) ? (int) $parameters['interval_seconds'] : null;

        if (! in_array($scheduleType, ['one_off', 'interval'], true)) {
            return ['success' => false, 'error' => 'schedule_type must be one of: one_off, interval'];
        }

        if ($scheduleType === 'interval' && ($intervalSeconds === null || $intervalSeconds < 1)) {
            return ['success' => false, 'error' => 'interval_seconds is required for interval tasks'];
        }

        $profile = null;
        if (! empty($parameters['agent_profile_id'])) {
            $profile = AgentProfile::query()->find($parameters['agent_profile_id']);

            if (! $profile) {
                return ['success' => false, 'error' => 'agent_profile_id was not found'];
            }
        }

        $inputPayload = $parameters['input_payload'] ?? [];
        if ($inputPayload !== null && ! is_array($inputPayload)) {
            return ['success' => false, 'error' => 'input_payload must be an object'];
        }

        try {
            $this->tenantScopeValidator()->assertScopeIsValid(
                $user,
                $organizationId,
                $teamId,
                allowUnbound: false,
            );

            $this->schemaValidation()->validatePayload(
                is_array($inputPayload) ? $inputPayload : [],
                $profile?->task_payload_schema,
            );
        } catch (ValidationException $exception) {
            return [
                'success' => false,
                'error' => collect($exception->errors())->flatten()->first() ?? 'Validation failed',
            ];
        }

        $task = AgentTask::create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'team_id' => $teamId,
            'agent_profile_id' => $profile?->id,
            'name' => $name,
            'prompt' => $prompt,
            'schedule_type' => $scheduleType,
            'execution_mode' => $parameters['execution_mode'] ?? null,
            'sandbox_profile' => $parameters['sandbox_profile'] ?? null,
            'interval_seconds' => $scheduleType === 'interval' ? $intervalSeconds : null,
            'agent_task_type' => $parameters['agent_task_type'] ?? 'background',
            'output_mode' => $parameters['output_mode'] ?? 'plain',
            'allowed_tools' => $parameters['allowed_tools'] ?? null,
            'allowed_outbound_hosts' => $parameters['allowed_outbound_hosts'] ?? null,
            'input_payload' => $inputPayload,
            'next_run_at' => $parameters['next_run_at'] ?? now(),
            'enabled' => array_key_exists('enabled', $parameters) ? (bool) $parameters['enabled'] : true,
            'max_attempts' => isset($parameters['max_attempts']) ? (int) $parameters['max_attempts'] : 3,
            'metadata' => [
                ...($parameters['metadata'] ?? []),
                'profile_metadata' => is_array($profile?->metadata) ? $profile->metadata : [],
            ],
        ]);

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
            ],
        ];
    }

    private function resolveCurrentUser(): ?User
    {
        $user = $this->user ?? Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function schemaValidation(): JsonSchemaValidationService
    {
        return $this->schemaValidation ?? app(JsonSchemaValidationService::class);
    }

    private function tenantScopeValidator(): TenantScopeValidator
    {
        return $this->tenantScopeValidator ?? app(TenantScopeValidator::class);
    }
}
