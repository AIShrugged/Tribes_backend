<?php

namespace App\Http\Requests\API\v1;

use App\Enums\InsightCategory;
use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InsightRequest extends FormRequest
{
    use PaginatedRequestTrait;

    public function rules(): array
    {
        $rules = [];
        $name  = $this->route()?->getName();

        if ($name === 'insight.profile.items') {
            $rules['category']    = ['nullable', 'string', Rule::in(InsightCategory::values())];
            $rules['is_archived'] = ['nullable', 'boolean'];
            $rules['offset']      = ['nullable', 'integer', 'min:0'];
            $rules['limit']       = ['nullable', 'integer', 'min:1', 'max:50'];
        }

        if ($name === 'insight.profile.sources') {
            $rules['offset'] = ['nullable', 'integer', 'min:0'];
            $rules['limit']  = ['nullable', 'integer', 'min:1', 'max:50'];
        }

        if ($name === 'insight.profile.history') {
            $rules['category'] = ['nullable', 'string', Rule::in(InsightCategory::values())];
            $rules['offset']   = ['nullable', 'integer', 'min:0'];
            $rules['limit']    = ['nullable', 'integer', 'min:1', 'max:50'];
        }

        if ($name === 'insight.relationships') {
            $rules['profile_a'] = ['required', 'integer', 'exists:profiles,id'];
            $rules['profile_b'] = ['required', 'integer', 'exists:profiles,id'];
        }

        return $rules;
    }

    public function getCategory(): ?string
    {
        return $this->input('category');
    }

    public function getIsArchived(): ?bool
    {
        $value = $this->input('is_archived');

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getProfileAId(): int
    {
        return (int) $this->input('profile_a');
    }

    public function getProfileBId(): int
    {
        return (int) $this->input('profile_b');
    }
}
