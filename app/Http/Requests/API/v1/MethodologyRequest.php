<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;
use Illuminate\Support\Facades\Auth;

class MethodologyRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function storeRules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'name'            => ['required', 'string', 'min:3', 'max:255'],
            'text'            => ['required', 'string', 'min:3'],
        ];
    }

    public function updateRules(): array
    {
        return [
            'methodology_id' => ['required', 'integer', 'exists:methodologies,id'],
            'name'           => ['required', 'string', 'min:3', 'max:255'],
            'text'           => ['required', 'string', 'min:3'],
        ];
    }

    public function destroyRules(): array
    {
        return [
            'methodology_id' => ['required', 'integer', 'exists:methodologies,id'],
        ];
    }

    public function getMethodologyId(): int
    {
        return $this->input('methodology_id');
    }

    public function getOrganizationId(): int
    {
        return $this->organization_id;
    }

    public function getStoreData(): array
    {
        return [
            'organization_id' => $this->organization_id,
            'name'            => $this->input('name'),
            'text'            => $this->input('text'),
        ];
    }

    public function getUpdateData(): array
    {
        return [
            'name' => $this->input('name'),
            'text' => $this->input('text'),
        ];
    }
}
