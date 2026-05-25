<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class LlmPromptRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function indexRules(): array
    {
        return [
            ...parent::indexRules(),
            'search' => ['nullable', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'prompt' => ['sometimes', 'string', 'min:1'],
        ];
    }

    public function seedRules(): array
    {
        return [
            'overwrite' => ['nullable', 'boolean'],
        ];
    }

    public function getUpdateData(): array
    {
        return array_filter([
            'name' => $this->input('name'),
            'prompt' => $this->input('prompt'),
        ], static fn ($value) => $value !== null);
    }
}
