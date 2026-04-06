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
            'page_html' => ['nullable', 'string', 'max:200000'],
            'page_title' => ['nullable', 'string', 'max:500'],
            'page_url' => ['nullable', 'string', 'max:2000'],
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
            'html' => $this->input('page_html'),
            'title' => $this->input('page_title'),
            'url' => $this->input('page_url'),
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'content' => [
                'description' => 'The message text to send to the bot. Max 10,000 characters.',
                'example'     => 'Summarise the key points from last week\'s meetings.',
            ],
            'page_html' => [
                'description' => 'Optional raw HTML of the current page. Added to the current message context only.',
                'example' => '<html><body><h1>Dashboard</h1><p>Open issues</p></body></html>',
            ],
            'page_title' => [
                'description' => 'Optional current page title.',
                'example' => 'Dashboard',
            ],
            'page_url' => [
                'description' => 'Optional current page URL.',
                'example' => 'https://app.example.com/dashboard',
            ],
        ];
    }

}
