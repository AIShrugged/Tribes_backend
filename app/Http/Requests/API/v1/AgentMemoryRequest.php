<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class AgentMemoryRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function indexRules(): array
    {
        return [
            ...parent::indexRules(),
            'agent_profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
            'scope_type' => ['nullable', 'string', 'max:32'],
            'scope_key' => ['nullable', 'string', 'max:191'],
            'kind' => ['nullable', 'string', 'max:32'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function getIndexFilters(): array
    {
        return [
            'agent_profile_id' => $this->input('agent_profile_id'),
            'scope_type' => $this->input('scope_type'),
            'scope_key' => $this->input('scope_key'),
            'kind' => $this->input('kind'),
            'active' => $this->has('active') ? $this->boolean('active') : null,
        ];
    }
}
