<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class AgentProfileRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function indexRules(): array
    {
        return [
            ...parent::indexRules(),
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    public function storeRules(): array
    {
        return [
            'key' => ['required', 'string', 'min:2', 'max:255', 'unique:agent_profiles,key'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string'],
            'system_prompt' => ['nullable', 'string'],
            'config_schema' => ['nullable', 'array'],
            'task_payload_schema' => ['nullable', 'array'],
            'execution_mode' => ['nullable', 'in:inline,isolated,paperclip'],
            'sandbox_profile' => ['nullable', 'string', 'max:64'],
            'allowed_tools' => ['nullable', 'array'],
            'allowed_tools.*' => ['string', 'max:255'],
            'allowed_outbound_hosts' => ['nullable', 'array'],
            'allowed_outbound_hosts.*' => ['string', 'max:255'],
            'default_model' => ['nullable', 'string', 'max:120'],
            'enabled' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function updateRules(): array
    {
        $profileId = $this->route('agentProfile')?->id;

        return [
            'key' => ['sometimes', 'string', 'min:2', 'max:255', 'unique:agent_profiles,key,'.$profileId],
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string'],
            'system_prompt' => ['nullable', 'string'],
            'config_schema' => ['nullable', 'array'],
            'task_payload_schema' => ['nullable', 'array'],
            'execution_mode' => ['sometimes', 'in:inline,isolated,paperclip'],
            'sandbox_profile' => ['nullable', 'string', 'max:64'],
            'allowed_tools' => ['prohibited'],
            'allowed_outbound_hosts' => ['nullable', 'array'],
            'allowed_outbound_hosts.*' => ['string', 'max:255'],
            'default_model' => ['nullable', 'string', 'max:120'],
            'enabled' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function validatePayloadRules(): array
    {
        return [
            'payload' => ['required', 'array'],
        ];
    }

    public function getStoreData(): array
    {
        return [
            'key' => $this->input('key'),
            'name' => $this->input('name'),
            'description' => $this->input('description'),
            'system_prompt' => $this->input('system_prompt'),
            'config_schema' => $this->input('config_schema'),
            'task_payload_schema' => $this->input('task_payload_schema'),
            'execution_mode' => $this->input('execution_mode', 'inline'),
            'sandbox_profile' => $this->input('sandbox_profile'),
            'allowed_tools' => $this->input('allowed_tools'),
            'allowed_outbound_hosts' => $this->input('allowed_outbound_hosts'),
            'default_model' => $this->input('default_model'),
            'enabled' => $this->boolean('enabled', true),
            'metadata' => $this->input('metadata'),
        ];
    }

    public function getUpdateData(): array
    {
        return array_filter([
            'key' => $this->input('key'),
            'name' => $this->input('name'),
            'description' => $this->input('description'),
            'system_prompt' => $this->input('system_prompt'),
            'config_schema' => $this->input('config_schema'),
            'task_payload_schema' => $this->input('task_payload_schema'),
            'execution_mode' => $this->input('execution_mode'),
            'sandbox_profile' => $this->input('sandbox_profile'),
            'allowed_outbound_hosts' => $this->input('allowed_outbound_hosts'),
            'default_model' => $this->input('default_model'),
            'enabled' => $this->has('enabled') ? $this->boolean('enabled') : null,
            'metadata' => $this->input('metadata'),
        ], static fn ($value) => $value !== null);
    }

    public function getPayloadData(): array
    {
        return $this->input('payload', []);
    }
}
