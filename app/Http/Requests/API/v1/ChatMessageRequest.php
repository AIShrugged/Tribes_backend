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

    public function getMessageContent(): string
    {
        return $this->input('content');
    }

    public function bodyParameters(): array
    {
        return [
            'content' => [
                'description' => 'The message text to send to the bot. Max 10,000 characters.',
                'example'     => 'Summarise the key points from last week\'s meetings.',
            ],
        ];
    }

}
