<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class ChatRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function storeRules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ];
    }

    public function indexRules(): array
    {
        return [
            'offset' => ['nullable', 'int', 'min:0'],
            'limit' => ['nullable', 'int', 'min:1', 'max:100'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ];
    }

    public function updateRules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'organization_id' => ['sometimes', 'nullable', 'integer', 'exists:organizations,id'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
        ];
    }

    public function getTitle(): ?string
    {
        return $this->input('title');
    }

    public function getOrganizationId(): ?int
    {
        return $this->filled('organization_id') ? (int) $this->input('organization_id') : null;
    }

    public function getTeamId(): ?int
    {
        return $this->filled('team_id') ? (int) $this->input('team_id') : null;
    }

    public function bodyParameters(): array
    {
        return [
            'title' => [
                'description' => 'Optional title for the chat. Max 255 characters.',
                'example'     => 'Q1 Strategy Discussion',
            ],
            'organization_id' => [
                'description' => 'Required organization binding for the chat.',
                'example'     => 1,
            ],
            'team_id' => [
                'description' => 'Optional team binding for the chat.',
                'example'     => 2,
            ],
        ];
    }

}
