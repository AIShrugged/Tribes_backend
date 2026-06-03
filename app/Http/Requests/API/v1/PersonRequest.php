<?php

namespace App\Http\Requests\API\v1;

use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;

class PersonRequest extends FormRequest
{
    use PaginatedRequestTrait;

    public function rules(): array
    {
        return [
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ];
    }

    public function getOrganizationId(): ?int
    {
        return $this->filled('organization_id')
            ? (int) $this->input('organization_id')
            : null;
    }
}
