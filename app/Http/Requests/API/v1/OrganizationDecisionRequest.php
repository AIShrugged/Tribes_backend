<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class OrganizationDecisionRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function indexRules(): array
    {
        return [
            'offset'      => ['nullable', 'int', 'min:0'],
            'limit'       => ['nullable', 'int', 'min:1', 'max:100'],
            'source_type' => ['nullable', 'string', 'in:meeting,manual,chat'],
            'search'      => ['nullable', 'string', 'max:255'],
        ];
    }

    public function getSearch(): ?string
    {
        return $this->search ? trim($this->search) : null;
    }

    public function getSourceType(): ?string
    {
        return $this->source_type;
    }
}
