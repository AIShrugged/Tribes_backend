<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class CommitReportIndexRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function indexRules(): array
    {
        return [
            'offset' => ['nullable', 'int', 'min:0'],
            'limit' => ['nullable', 'int', 'min:1', 'max:100'],
            'repo' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'include_empty' => ['nullable', 'boolean'],
        ];
    }

    public function getRepo(): ?string
    {
        return $this->repo ? trim($this->repo) : null;
    }

    public function getBranch(): ?string
    {
        return $this->branch ? trim($this->branch) : null;
    }

    public function getFrom(): ?string
    {
        return $this->from ? trim((string) $this->from) : null;
    }

    public function getTo(): ?string
    {
        return $this->to ? trim((string) $this->to) : null;
    }

    public function includeEmpty(): bool
    {
        return $this->boolean('include_empty');
    }
}
