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
            'content'    => ['required', 'string', 'max:10000'],
            'page_text'  => ['nullable', 'string', 'max:30000'],
            'page_title' => ['nullable', 'string', 'max:500'],
            'page_url'   => ['nullable', 'string', 'max:2000'],
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

    public function getPageContext(): array
    {
        return [
            'text'  => $this->input('page_text'),
            'title' => $this->input('page_title'),
            'url'   => $this->input('page_url'),
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'content'    => [
                'description' => 'The message text to send to the bot. Max 10,000 characters.',
                'example'     => 'Summarise the key points from last week\'s meetings.',
            ],
            'page_text'  => [
                'description' => 'Optional visible text of the current page (extracted client-side, e.g. document.body.innerText). Max 30,000 characters.',
                'example'     => 'Dashboard\nOpen issues: 12\nIn progress: 4',
            ],
            'page_title' => [
                'description' => 'Optional page title.',
                'example'     => 'Dashboard',
            ],
            'page_url'   => [
                'description' => 'Optional page URL.',
                'example'     => 'https://app.example.com/dashboard',
            ],
        ];
    }

}
