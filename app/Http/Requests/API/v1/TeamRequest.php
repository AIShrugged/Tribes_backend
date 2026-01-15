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
            'name' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function updateRules(): array
    {
        return [
            'id'   => ['required', 'int', 'exists:teams,id'],
            'name' => ['sometimes', 'string', 'min:3', 'max:255'],
            'slug' => ['sometimes', 'string', 'min:3', 'max:255'],
        ];
    }

    public function assignMethodologyForTeamRules(): array
    {
        return [
            'methodology_id' => ['required', 'integer', 'exists:methodologies,id'],
        ];
    }

    public function getMethodologyId(): int
    {
        return $this->methodology_id;
    }

    public function getStoreData(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'name'            => $this->name,
            'slug'            => Str::slug($this->name),
            'methodology_id'  => Methodology::getDefault()->id
        ];
    }

    public function getUpdateData(): array
    {
        return array_filter([
            'name' => $this->name,
            'slug' => $this->slug ?? Str::slug($this->name),
        ]);
    }
}
