<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueStatsHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period'          => ['required', Rule::in(['day', 'week', 'month'])],
            'range'           => ['nullable', 'integer', 'min:1', 'max:365'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ];
    }

    public function getPeriod(): string
    {
        return $this->validated('period');
    }

    public function getRange(): int
    {
        return (int) ($this->validated('range') ?? match ($this->getPeriod()) {
            'day'   => 30,
            'week'  => 12,
            'month' => 12,
        });
    }

    public function getOrganizationId(): ?int
    {
        return $this->validated('organization_id') !== null
            ? (int) $this->validated('organization_id')
            : null;
    }
}
