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

    public function getTitle(): ?string
    {
        return $this->input('title');
    }
}
