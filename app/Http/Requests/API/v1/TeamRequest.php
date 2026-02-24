<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Models\Methodology;
use App\Traits\PaginatedRequestTrait;
use Illuminate\Support\Str;

class TeamRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function storeRules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'name'            => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:255'],
            'slug' => ['sometimes', 'string', 'min:3', 'max:255'],
        ];
    }

    public function assignMethodologyForTeamRules(): array
    {
        return [
            'team_id'        => ['required', 'integer', 'exists:teams,id'],
            'methodology_id' => ['required', 'integer', 'exists:methodologies,id'],
        ];
    }

    public function getMethodologyId(): int
    {
        return $this->methodology_id;
    }

    public function getOrganizationId(): int
    {
        return $this->organization_id;
    }

    public function getTeamId(): int
    {
        return $this->team_id;
    }

    public function getSlug(): string
    {
        return $this->slug ?? Str::slug($this->name);
    }

    public function getStoreData(): array
    {
        return [
            'organization_id' => $this->organization_id,
            'name'            => $this->name,
            'slug'            => Str::slug($this->name),
            'methodology_id'  => Methodology::getDefault()->id
        ];
    }

    public function getUpdateData(): array
    {
        return array_filter([
            'name' => $this->name,
            'slug' => $this->getSlug(),
        ]);
    }

    public function bodyParameters(): array
    {
        $action = $this->route()?->getActionMethod();

        if ($action === 'store') {
            return [
                'organization_id' => [
                    'description' => 'Organization ID the team belongs to.',
                    'example'     => 1,
                ],
                'name' => [
                    'description' => 'Team name (min 3, max 255 characters).',
                    'example'     => 'Core Team',
                ],
            ];
        }

        if ($action === 'update') {
            return [
                'name' => [
                    'description' => 'Team name.',
                    'example'     => 'Platform Team',
                ],
                'slug' => [
                    'description' => 'Team slug (auto-generated from name if omitted).',
                    'example'     => 'platform-team',
                ],
            ];
        }

        if ($action === 'assignMethodologyForTeam') {
            return [
                'methodology_id' => [
                    'description' => 'The methodology ID to assign.',
                    'example'     => 2,
                ],
            ];
        }

        return [];
    }

}
