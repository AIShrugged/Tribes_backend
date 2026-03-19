<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class WorkspaceRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function storeRules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'scope_type' => ['required', 'string', 'in:org_shared,team_shared,user_private,user_team_private,personal_shared'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function contentsRules(): array
    {
        return [
            'path' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function readFileRules(): array
    {
        return [
            'path' => ['required', 'string', 'max:2048'],
            'max_bytes' => ['nullable', 'integer', 'min:1', 'max:1048576'],
        ];
    }

    public function writeFileRules(): array
    {
        return [
            'path' => ['required', 'string', 'max:2048'],
            'contents' => ['required', 'string'],
        ];
    }

    public function deleteFileRules(): array
    {
        return [
            'path' => ['required', 'string', 'max:2048'],
        ];
    }

    public function createDirectoryRules(): array
    {
        return [
            'path' => ['required', 'string', 'max:2048'],
        ];
    }

    public function storePermissionRules(): array
    {
        return [
            'principal_type' => ['required', 'string', 'in:user,team'],
            'principal_id' => ['required', 'string', 'max:64'],
            'can_list' => ['sometimes', 'boolean'],
            'can_read' => ['sometimes', 'boolean'],
            'can_write' => ['sometimes', 'boolean'],
            'can_delete' => ['sometimes', 'boolean'],
            'can_execute' => ['sometimes', 'boolean'],
            'can_admin' => ['sometimes', 'boolean'],
        ];
    }

    public function getStoreData(): array
    {
        return $this->only(['organization_id', 'team_id', 'owner_user_id', 'name', 'slug', 'scope_type', 'metadata']);
    }

    public function getUpdateData(): array
    {
        return $this->only(['name', 'slug', 'status', 'metadata']);
    }

    public function getFilePath(): string
    {
        return (string) ($this->query('path') ?? $this->input('path', ''));
    }

    public function getFileContents(): string
    {
        return (string) $this->input('contents', '');
    }

    public function getMaxBytes(): ?int
    {
        $value = $this->query('max_bytes') ?? $this->input('max_bytes');

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function getPermissionData(): array
    {
        return [
            'principal_type' => (string) $this->input('principal_type'),
            'principal_id' => (string) $this->input('principal_id'),
            'abilities' => [
                'can_list' => (bool) $this->boolean('can_list'),
                'can_read' => (bool) $this->boolean('can_read'),
                'can_write' => (bool) $this->boolean('can_write'),
                'can_delete' => (bool) $this->boolean('can_delete'),
                'can_execute' => (bool) $this->boolean('can_execute'),
                'can_admin' => (bool) $this->boolean('can_admin'),
            ],
        ];
    }
}
