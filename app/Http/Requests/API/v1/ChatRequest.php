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
        ];
    }

    public function updateRules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('chat')) {
            $this->merge(['chat_id' => (int) $this->route('chat')]);
        }
    }

    public function getChatId(): int
    {
        return $this->chat_id;
    }

    public function getTitle(): ?string
    {
        return $this->input('title');
    }
}
