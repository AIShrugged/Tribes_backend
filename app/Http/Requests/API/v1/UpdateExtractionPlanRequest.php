<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a moderation-plan edit. Only the editable fields are allowed; issue name/description are
 * intentionally absent (LOCKED — load-bearing for future merge-LLM matching). Authorization is done
 * in the controller via the upload visibility scope + feature flag (matches the onboarding requests).
 */
class UpdateExtractionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'issues'                 => ['sometimes', 'array'],
            'issues.*.uid'           => ['required_with:issues', 'string'],
            'issues.*.assignee_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issues.*.due_date'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'issues.*.priority'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'issues.*.type'          => ['sometimes', 'nullable', 'string', 'max:50'],

            'decisions'              => ['sometimes', 'array'],
            'decisions.*.uid'        => ['required_with:decisions', 'string'],
            'decisions.*.text'       => ['sometimes', 'string'],
            'decisions.*.author_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'decisions.*.topic'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'decisions.*.skip'       => ['sometimes', 'boolean'],
        ];
    }
}
