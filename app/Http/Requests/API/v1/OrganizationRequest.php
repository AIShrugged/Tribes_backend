<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;
use Illuminate\Support\Str;

class OrganizationRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function storeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['id' => $this->route('organization')]);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStoreData(): array
    {
        return [
            'name' => $this->name,
            'slug' => Str::slug($this->name),
        ];
    }

    public function getUpdateData(): array
    {
        return array_filter([
            'name' => $this->name,
            'slug' => $this->slug ?? Str::slug($this->name),
        ]);
    }

    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Organization name.',
                'example'     => 'Acme Inc',
            ],
            'slug' => [
                'description' => 'Organization slug (auto-generated from name if omitted).',
                'example'     => 'acme-inc',
            ],
        ];
    }

}
