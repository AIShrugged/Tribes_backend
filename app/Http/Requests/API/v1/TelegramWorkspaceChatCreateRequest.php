<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;

class TelegramWorkspaceChatCreateRequest extends ApiResourceRequest
{
    public function storeRules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['required', 'integer'],
            'message_thread_id' => ['nullable', 'integer'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ];
    }

    public function getName(): ?string
    {
        return $this->filled('name') ? (string) $this->input('name') : null;
    }

    public function getTelegramChatId(): int
    {
        return (int) $this->input('telegram_chat_id');
    }

    public function getMessageThreadId(): ?int
    {
        return $this->filled('message_thread_id') ? (int) $this->input('message_thread_id') : null;
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
