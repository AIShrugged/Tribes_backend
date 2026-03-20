<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;

class TelegramChatAttachCodeRequest extends ApiResourceRequest
{
    public function storeRules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ];
    }

    public function getOrganizationId(): int
    {
        return (int) $this->input('organization_id');
    }

    public function getTeamId(): ?int
    {
        return $this->filled('team_id') ? (int) $this->input('team_id') : null;
    }
}
