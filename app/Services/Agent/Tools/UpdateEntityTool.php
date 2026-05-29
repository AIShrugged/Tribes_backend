<?php

namespace App\Services\Agent\Tools;

use App\Enums\InsightContextType;
use App\Enums\MeetingTaskStatus;
use App\Models\AgentTask;
use App\Models\Channel;
use App\Models\InsightShortTerm;
use App\Models\Issue;
use App\Models\OrganizationContext;
use App\Models\Profile;
use App\Models\User;
use App\Services\AgentTaskMutationService;
use App\Services\TenantScopeValidator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateEntityTool extends AbstractAgentTool
{
    public function __construct(
        private readonly User $user,
        private readonly AgentTaskMutationService $taskMutationService,
        private readonly TenantScopeValidator $tenantScopeValidator,
        private readonly string $channel = 'web',
        private readonly ?int $organizationId = null,
        private readonly ?int $teamId = null,
    ) {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'update_entity';
    }

    public function getDescription(): string
    {
        $contextTypes = collect(InsightContextType::cases())
            ->map(fn ($case) => $case->value)
            ->join(', ');

        $validStatuses = implode(', ', array_map(fn ($s) => $s->value, MeetingTaskStatus::cases()));

        return 'Update an existing entity. '
            ."Use entity=\"issue\" to change issue fields such as status ({$validStatuses}), team_id, organization_id, assignee_id, due_date, priority, name, description, type, or epic_id. "
            .'entity="agent_task" to modify agent task parameters, '
            .'entity="organization_context" to update an indexed organization context chunk, '
            ."entity=\"memory\" to save notes about the user (context_type: {$contextTypes}).";
    }

    public function getParameters(): array
    {
        $validStatuses = array_map(fn ($s) => $s->value, MeetingTaskStatus::cases());

        return [
            'type' => 'object',
            'required' => ['entity', 'data'],
            'properties' => [
                'entity' => [
                    'type' => 'string',
                    'enum' => ['issue', 'agent_task', 'organization_context', 'memory'],
                    'description' => 'Type of entity to update.',
                ],
                'id' => [
                    'type' => 'integer',
                    'description' => 'ID of the entity to update. Required for issue, agent_task, and organization_context.',
                ],
                'data' => [
                    'type' => 'object',
                    'description' => 'Fields to update.',
                    'properties' => [
                        // issue
                        'status' => [
                            'type' => 'string',
                            'enum' => $validStatuses,
                            'description' => 'New status (issue only).',
                        ],
                        'description' => ['type' => ['string', 'null']],
                        'type' => ['type' => 'string', 'enum' => Issue::TYPES],
                        'assignee_id' => ['type' => ['integer', 'null']],
                        'author_id' => ['type' => ['integer', 'null']],
                        'due_date' => ['type' => ['string', 'null']],
                        'priority' => ['type' => ['integer', 'null']],
                        'epic_id' => ['type' => ['integer', 'null']],
                        // agent_task
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
                        'allowed_tools' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                        'allowed_outbound_hosts' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                        'input_payload' => ['type' => ['object', 'null'], 'additionalProperties' => true],
                        'next_run_at' => ['type' => ['string', 'null']],
                        'enabled' => ['type' => ['boolean', 'null']],
                        'max_attempts' => ['type' => ['integer', 'null']],
                        'metadata' => ['type' => ['object', 'null'], 'additionalProperties' => true],
                        // organization_context
                        'text' => [
                            'type' => 'string',
                            'description' => 'Full replacement text for the indexed organization context chunk.',
                        ],
                        // memory
                        'context_type' => [
                            'type' => 'string',
                            'enum' => InsightContextType::values(),
                            'description' => 'Memory context type (memory only).',
                        ],
                        'memory_text' => [
                            'type' => 'string',
                            'description' => 'Memory text written as notes to yourself (memory only).',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function execute(?array $parameters): mixed
    {
        $parameters = $parameters ?? [];
        $entity = $parameters['entity'] ?? '';
        $id = isset($parameters['id']) ? (int) $parameters['id'] : null;
        $data = $parameters['data'] ?? [];

        if (! is_array($data)) {
            $data = [];
        }

        return match ($entity) {
            'issue' => $this->updateIssue($id, $data),
            'agent_task' => $this->updateAgentTask($id, $data),
            'organization_context' => $this->updateOrganizationContext($id, $data),
            'memory' => $this->updateMemory($data),
            default => ['success' => false, 'error' => "Unknown entity: {$entity}. Must be one of: issue, agent_task, organization_context, memory"],
        };
    }

    private function updateIssue(?int $id, array $data): array
    {
        if (! $id) {
            return ['success' => false, 'error' => 'id is required for issue'];
        }

        $issue = Issue::query()->visibleTo($this->user)->find($id);
        if (! $issue) {
            return ['success' => false, 'error' => 'Issue not found or access denied'];
        }

        $updateData = $this->extractIssueUpdateData($data);
        if ($updateData === []) {
            return ['success' => false, 'error' => 'No updatable fields were provided for issue'];
        }

        if (array_key_exists('type', $updateData)) {
            $updateData['type'] = Issue::normalizeType($updateData['type']) ?? $updateData['type'];
        }

        if (array_key_exists('author_id', $updateData)) {
            $updateData['user_id'] = $updateData['author_id'];
            unset($updateData['author_id']);
        }

        try {
            $this->validateIssueUpdateData($updateData, $issue);
            $this->assertIssueMatchesScope($issue);

            $effectiveOrganizationId = array_key_exists('organization_id', $updateData)
                ? (int) $updateData['organization_id']
                : (int) $issue->organization_id;
            $effectiveTeamId = array_key_exists('team_id', $updateData)
                ? ($updateData['team_id'] !== null ? (int) $updateData['team_id'] : null)
                : $issue->team_id;

            $this->tenantScopeValidator->assertScopeIsValid($this->user, $effectiveOrganizationId, $effectiveTeamId, allowUnbound: false);
            $this->assertIssueTargetMatchesScope($effectiveOrganizationId, $effectiveTeamId);
        } catch (ValidationException $exception) {
            return [
                'success' => false,
                'error' => 'Issue validation failed.',
                'details' => $exception->errors(),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $oldValues = $issue->only(array_keys($updateData));
        $issue->update($updateData);
        $issue->refresh();

        return [
            'success' => true,
            'task_id' => $issue->id,
            'name' => $issue->name,
            'old_status' => $oldValues['status'] ?? null,
            'new_status' => $issue->status,
            'issue' => [
                'id' => $issue->id,
                'name' => $issue->name,
                'description' => $issue->description,
                'type' => $issue->type,
                'status' => $issue->status,
                'organization_id' => $issue->organization_id,
                'team_id' => $issue->team_id,
                'assignee_id' => $issue->assignee_id,
                'author_id' => $issue->user_id,
                'due_date' => $issue->due_date?->toDateString(),
                'priority' => $issue->priority,
                'epic_id' => $issue->epic_id,
            ],
            'old_values' => $oldValues,
        ];
    }

    private function updateAgentTask(?int $id, array $data): array
    {
        if (! $id) {
            return ['success' => false, 'error' => 'id is required for agent_task'];
        }

        $task = AgentTask::query()->find($id);

        if (! $task) {
            return ['success' => false, 'error' => 'Agent task not found or access denied'];
        }

        if (! $this->user->isOrganizationMember($task->organization_id)) {
            return ['success' => false, 'error' => 'Agent task not found or access denied'];
        }

        $updateData = $this->extractAgentTaskUpdateData($data);
        if ($updateData === []) {
            return ['success' => false, 'error' => 'No updatable fields were provided'];
        }

        try {
            $this->validateAgentTaskUpdateData($updateData);
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

    private function updateOrganizationContext(?int $id, array $data): array
    {
        if (! $id) {
            return ['success' => false, 'error' => 'id is required for organization_context'];
        }

        $context = OrganizationContext::query()->find($id);
        if (! $context) {
            return ['success' => false, 'error' => 'Organization context not found or access denied'];
        }

        $text = array_key_exists('text', $data) ? trim((string) $data['text']) : '';
        if ($text === '') {
            return ['success' => false, 'error' => 'data.text is required for organization_context'];
        }

        try {
            $this->assertOrganizationContextMatchesScope($context);

            if (! $this->user->isOrganizationMember((int) $context->organization_id)) {
                return ['success' => false, 'error' => 'Organization context not found or access denied'];
            }
        } catch (ValidationException $exception) {
            return [
                'success' => false,
                'error' => 'Organization context validation failed.',
                'details' => $exception->errors(),
            ];
        }

        $oldText = $context->text;
        $context->update(['text' => $text, 'indexed_at' => now()]);
        $context->refresh();

        return [
            'success' => true,
            'organization_context' => [
                'id' => $context->id,
                'organization_id' => $context->organization_id,
                'source_type' => $context->source_type,
                'source_id' => $context->source_id,
                'text' => $context->text,
                'indexed_at' => $context->indexed_at?->toIso8601String(),
            ],
            'old_values' => [
                'text' => $oldText,
            ],
        ];
    }

    private function updateMemory(array $data): array
    {
        $contextType = $data['context_type'] ?? '';
        $memoryText = $data['memory_text'] ?? '';

        if (empty($contextType) || empty($memoryText)) {
            return ['success' => false, 'error' => 'data.context_type and data.memory_text are required for memory'];
        }

        $validContextType = InsightContextType::tryFrom($contextType);
        if (! $validContextType) {
            return [
                'success' => false,
                'error' => 'Invalid data.context_type. Must be one of: '.implode(', ', InsightContextType::values()),
            ];
        }

        $channelId = Channel::idFor($this->channel);
        $identifier = $this->user->resolveChannelIdentifier($this->channel);

        if (! $channelId || ! $identifier) {
            return ['success' => false, 'error' => "Cannot resolve channel profile for channel '{$this->channel}'."];
        }

        try {
            $profile = Profile::firstOrCreate(
                ['channel_id' => $channelId, 'channel_identifier' => $identifier],
                ['user_id' => $this->user->id]
            );

            InsightShortTerm::updateOrCreate(
                ['profile_id' => $profile->id, 'context_type' => $validContextType],
                ['content' => ['text' => $memoryText], 'expires_at' => now()->addMonths(3)]
            );

            return ['success' => true, 'message' => "Memory updated successfully (context: {$validContextType->value})"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function extractAgentTaskUpdateData(array $data): array
    {
        $fields = [
            'name', 'prompt', 'organization_id', 'team_id', 'agent_profile_id',
            'schedule_type', 'execution_mode', 'sandbox_profile', 'interval_seconds',
            'agent_task_type', 'output_mode', 'allowed_tools', 'allowed_outbound_hosts',
            'input_payload', 'next_run_at', 'enabled', 'max_attempts', 'metadata',
        ];

        $result = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $result[$field] = $data[$field];
            }
        }

        return $result;
    }

    private function extractIssueUpdateData(array $data): array
    {
        $fields = [
            'name', 'description', 'type', 'status', 'organization_id', 'team_id',
            'assignee_id', 'author_id', 'due_date', 'priority', 'epic_id',
        ];

        $result = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $result[$field] = $data[$field];
            }
        }

        return $result;
    }

    private function validateIssueUpdateData(array $data, Issue $issue): void
    {
        $validStatuses = array_map(fn ($s) => $s->value, MeetingTaskStatus::cases());

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'required', 'in:'.implode(',', Issue::TYPES)],
            'status' => ['sometimes', 'required', 'in:'.implode(',', $validStatuses)],
            'organization_id' => ['sometimes', 'required', 'integer', 'exists:organizations,id'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', 'nullable', 'integer', 'min:-1000000', 'max:1000000'],
            'epic_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('issues', 'id')->where('type', Issue::TYPE_EPIC),
                function (string $attribute, mixed $value, \Closure $fail) use ($data, $issue): void {
                    $type = $data['type'] ?? $issue->type;
                    if ($value !== null && Issue::normalizeType((string) $type) === Issue::TYPE_EPIC) {
                        $fail('Epic issues cannot be assigned to another epic.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function validateAgentTaskUpdateData(array $data): void
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

    private function assertIssueMatchesScope(Issue $issue): void
    {
        if ($this->organizationId !== null && (int) $issue->organization_id !== $this->organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => ['Issue updates are restricted to the current organization scope.'],
            ]);
        }

        if ($this->teamId !== null && $issue->team_id !== null && (int) $issue->team_id !== $this->teamId) {
            throw ValidationException::withMessages([
                'team_id' => ['Issue updates are restricted to the current team scope.'],
            ]);
        }
    }

    private function assertIssueTargetMatchesScope(int $organizationId, ?int $teamId): void
    {
        if ($this->organizationId !== null && $organizationId !== $this->organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => ['Issue updates are restricted to the current organization scope.'],
            ]);
        }

        if ($this->teamId !== null && $teamId !== $this->teamId) {
            throw ValidationException::withMessages([
                'team_id' => ['Issue updates are restricted to the current team scope.'],
            ]);
        }
    }

    private function assertOrganizationContextMatchesScope(OrganizationContext $context): void
    {
        if ($this->organizationId !== null && (int) $context->organization_id !== $this->organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => ['Organization context updates are restricted to the current organization scope.'],
            ]);
        }
    }
}
