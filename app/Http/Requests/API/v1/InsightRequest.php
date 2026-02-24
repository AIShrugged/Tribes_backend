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

    public function queryParameters(): array
    {
        $name   = $this->route()?->getName();
        $params = [];

        if (in_array($name, ['insight.profile.items', 'insight.profile.sources', 'insight.profile.history'])) {
            $params['offset'] = [
                'description' => 'Number of items to skip.',
                'example'     => 0,
            ];
            $params['limit'] = [
                'description' => 'Maximum number of items to return (max 50).',
                'example'     => 25,
            ];
        }

        if ($name === 'insight.profile.items') {
            $params['category'] = [
                'description' => 'Filter by knowledge category. Allowed values: communication_style, work_patterns, strengths, development_areas, goals_motivations, psychological_profile.',
                'example'     => 'communication_style',
            ];
            $params['is_archived'] = [
                'description' => 'Filter by archive status. Omit to return all.',
                'example'     => false,
            ];
        }

        if ($name === 'insight.profile.history') {
            $params['category'] = [
                'description' => 'Filter history by knowledge category.',
                'example'     => 'communication_style',
            ];
        }

        if ($name === 'insight.relationships') {
            $params['profile_a'] = [
                'description' => 'ID of the first profile.',
                'example'     => 42,
            ];
            $params['profile_b'] = [
                'description' => 'ID of the second profile.',
                'example'     => 15,
            ];
        }

        return $params;
    }

}
