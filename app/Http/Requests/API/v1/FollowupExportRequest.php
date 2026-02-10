<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use Illuminate\Validation\Rule;

class FollowupExportRequest extends ApiResourceRequest
{
    public function exportRules(): array
    {
        return [
            'format' => ['required', Rule::in(['pdf', 'excel', 'html'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'followup_id' => (int) $this->route('followup'),
            'format'      => $this->query('format', 'pdf'),
        ]);
    }

    public function getFollowupId(): int
    {
        return $this->followup_id;
    }

    public function getFormat(): string
    {
        return $this->format;
    }
}
