<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;
use Illuminate\Validation\Rule;
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
            'issue_types' => ['sometimes', 'array'],
            'issue_types.*.key' => ['required_with:issue_types', 'string', 'max:255'],
            'issue_types.*.name' => ['required_with:issue_types', 'string', 'max:255'],
            'issue_types.*.base_type' => ['required_with:issue_types', Rule::in(['development', 'organization'])],
            'issue_types.*.agent_profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
            'issue_types.*.metadata' => ['nullable', 'array'],
            'issue_types.*.is_active' => ['sometimes', 'boolean'],
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
            'issue_types' => $this->input('issue_types'),
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
            'issue_types' => [
                'description' => 'Resolved task types and their agent profile mappings.',
                'example' => [
                    [
                        'key' => 'backend',
                        'name' => 'Backend',
                        'base_type' => 'development',
                        'agent_profile_id' => 1,
                    ],
                ],
            ],
        ];
    }

}
