<?php

namespace App\Http\Requests\API\v1;

use App\Enums\MeetingTaskStatus;
use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueRequest extends FormRequest
{
    use PaginatedRequestTrait;

    private const VALID_STATUSES = ['open', 'in_progress', 'paused', 'review', 'reopen', 'done'];

    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'issues.index' => [
                'status' => ['nullable', Rule::in(self::VALID_STATUSES)],
                'type' => ['nullable', Rule::in(['task', 'bug'])],
                'assignee' => ['nullable', 'integer', 'exists:users,id'],
                'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
                'team_id' => ['nullable', 'integer', 'exists:teams,id'],
                'offset' => ['nullable', 'integer', 'min:0'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            ],
            'issues.store' => [
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'type' => ['required', Rule::in(['task', 'bug'])],
                'status' => ['nullable', Rule::in(self::VALID_STATUSES)],
                'organization_id' => ['required', 'integer', 'exists:organizations,id'],
                'team_id' => ['nullable', 'integer', 'exists:teams,id'],
                'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            ],
            'issues.update' => [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string'],
                'type' => ['sometimes', 'required', Rule::in(['task', 'bug'])],
                'status' => ['sometimes', 'required', Rule::in(self::VALID_STATUSES)],
                'organization_id' => ['sometimes', 'required', 'integer', 'exists:organizations,id'],
                'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
                'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
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
            ]);
        }
    }

    public function getIndexFilters(): array
    {
        return [
            'status' => $this->input('status'),
            'type' => $this->input('type'),
            'assignee_id' => $this->input('assignee'),
            'organization_id' => $this->input('organization_id'),
            'team_id' => $this->input('team_id'),
        ];
    }

    public function getStoreData(): array
    {
        return $this->only(['name', 'description', 'type', 'status', 'organization_id', 'team_id', 'assignee_id']);
    }

    public function getUpdateData(): array
    {
        return $this->only(['name', 'description', 'type', 'status', 'organization_id', 'team_id', 'assignee_id']);
    }
}
