<?php

namespace App\Http\Requests\API\v1;

use App\Support\UploadStatus;
use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadLogRequest extends FormRequest
{
    use PaginatedRequestTrait;

    public function rules(): array
    {
        // Explicit limit min/max — PaginatedRequestTrait::getPaginationRules has a
        // known min:/max: bug, so mirror IssueRequest's explicit rules. The max:100
        // caps the in-PHP merge so the feed can't be used as a load amplifier.
        return [
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:100'],
            'type'   => ['nullable', Rule::in(['transcript', 'task_data'])],
            'status' => ['nullable', Rule::in(UploadStatus::NORMALIZED)],
        ];
    }
}
