<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class AgentTaskRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function indexRules(): array
    {
        return [
            ...parent::indexRules(),
            'enabled' => ['nullable', 'boolean'],
            'schedule_type' => ['nullable', 'in:one_off,interval'],
            'agent_profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
        ];
    }

    public function storeRules(): array
    {
        return $this->taskRules();
    }

    public function updateRules(): array
    {
        return $this->taskRules(sometimes: true);
    }

    public function getIndexFilters(): array
    {
        return [
            'enabled' => $this->has('enabled') ? $this->boolean('enabled') : null,
            'schedule_type' => $this->input('schedule_type'),
            'agent_profile_id' => $this->input('agent_profile_id'),
        ];
    }

    public function getStoreData(): array
    {
        return $this->normalizeTaskData();
    }

    public function getUpdateData(): array
    {
        return $this->normalizeTaskData(onlyProvided: true);
    }

    private function taskRules(bool $sometimes = false): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:255'],
            'prompt' => [$required, 'string'],
            'agent_profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
            'schedule_type' => [$required, 'in:one_off,interval'],
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
        ];
    }

    private function normalizeTaskData(bool $onlyProvided = false): array
    {
        $defaults = [
            'schedule_type' => 'one_off',
            'agent_task_type' => 'background',
            'output_mode' => 'plain',
            'enabled' => true,
            'max_attempts' => 3,
        ];

        $fields = [
            'name',
            'prompt',
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
            if ($onlyProvided && ! $this->exists($field)) {
                continue;
            }

            $data[$field] = match ($field) {
                'enabled' => $this->boolean('enabled', $defaults['enabled']),
                'schedule_type' => $this->input('schedule_type', $defaults['schedule_type']),
                'agent_task_type' => $this->input('agent_task_type', $defaults['agent_task_type']),
                'output_mode' => $this->input('output_mode', $defaults['output_mode']),
                'max_attempts' => (int) $this->input('max_attempts', $defaults['max_attempts']),
                default => $this->input($field),
            };
        }

        return $data;
    }
}
