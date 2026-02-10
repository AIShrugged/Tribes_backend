<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class ChatMessageRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function storeRules(): array
    {
        return [
            'content' => ['required', 'string', 'max:10000'],
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

    public function getMessageContent(): string
    {
        return $this->input('content');
    }
}
