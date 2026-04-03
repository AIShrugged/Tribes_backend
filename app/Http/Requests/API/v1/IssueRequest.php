<?php

namespace App\Http\Requests\API\v1;

use App\Enums\MeetingTaskStatus;
use App\Models\Issue;
use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueRequest extends FormRequest
{
    use PaginatedRequestTrait;

    private const VALID_STATUSES = ['open', 'in_progress', 'paused', 'reviewed', 'review', 'reopen', 'done'];

    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'issues.index' => [
                'status' => ['nullable', Rule::in(self::VALID_STATUSES)],
                'type' => ['nullable', Rule::in(Issue::TYPES)],
                'assignee' => ['nullable', 'integer', 'exists:users,id'],
                'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
                'team_id' => ['nullable', 'integer', 'exists:teams,id'],
                'offset' => ['nullable', 'integer', 'min:0'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
                'sort' => ['nullable', Rule::in(['id', 'name', 'status', 'type', 'updated_at', 'created_at'])],
                'order' => ['nullable', Rule::in(['asc', 'desc'])],
                'search' => ['nullable', 'string', 'max:255'],
                'id_from' => ['nullable', 'integer', 'min:1'],
                'id_to' => ['nullable', 'integer', 'min:1'],
            ],
            'issues.store' => [
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'type' => ['required', Rule::in(Issue::TYPES)],
                'status' => ['nullable', Rule::in(self::VALID_STATUSES)],
                'organization_id' => ['required', 'integer', 'exists:organizations,id'],
                'team_id' => ['nullable', 'integer', 'exists:teams,id'],
                'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            ],
            'issues.update' => [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string'],
                'type' => ['sometimes', 'required', Rule::in(Issue::TYPES)],
                'status' => ['sometimes', 'required', Rule::in(self::VALID_STATUSES)],
                'organization_id' => ['sometimes', 'required', 'integer', 'exists:organizations,id'],
                'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
                'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            ],
            'issues.dispatch' => [
                'agent_profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
            ],
            'issues.attachments.store' => [
                'file' => ['required', 'file', 'max:10240'],
            ],
            default => [],
        };
    }

    public function prepareForValidation(): void
    {
        if ($this->route()?->getName() === 'issues.index') {
            $this->merge([
                'assignee' => $this->query('assignee'),
                'organization_id' => $this->query('organization_id'),
                'team_id' => $this->query('team_id'),
                'sort' => $this->query('sort'),
                'order' => $this->query('order'),
                'search' => $this->query('search'),
            ]);
        }
    }

    public function getIndexFilters(): array
    {
        return [
            'status' => $this->input('status'),
            'type' => Issue::normalizeType($this->input('type')) ?? $this->input('type'),
            'assignee_id' => $this->input('assignee'),
            'organization_id' => $this->input('organization_id'),
            'team_id' => $this->input('team_id'),
            'sort' => filled($this->input('sort')) ? $this->input('sort') : 'updated_at',
            'order' => filled($this->input('order')) ? $this->input('order') : 'desc',
            'search' => $this->input('search'),
            'id_from' => $this->integer('id_from') ?: null,
            'id_to' => $this->integer('id_to') ?: null,
        ];
    }

    public function getStoreData(): array
    {
        return [
            'name' => $this->input('name'),
            'description' => $this->input('description'),
            'type' => Issue::normalizeType($this->input('type')) ?? $this->input('type'),
            'status' => $this->input('status'),
            'organization_id' => $this->input('organization_id'),
            'team_id' => $this->input('team_id'),
            'assignee_id' => $this->input('assignee_id'),
        ];
    }

    public function getUpdateData(): array
    {
        return array_filter([
            'name' => $this->input('name'),
            'description' => $this->input('description'),
            'type' => Issue::normalizeType($this->input('type')) ?? $this->input('type'),
            'status' => $this->input('status'),
            'organization_id' => $this->input('organization_id'),
            'team_id' => $this->input('team_id'),
            'assignee_id' => $this->input('assignee_id'),
        ], static fn ($value) => $value !== null);
    }
}