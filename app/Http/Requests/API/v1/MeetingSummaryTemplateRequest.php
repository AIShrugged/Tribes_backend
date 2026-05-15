<?php

namespace App\Http\Requests\API\v1;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MeetingSummaryTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sections'           => ['required', 'array', 'min:1'],
            'sections.*'         => [
                'string',
                'in:key_points,decisions,commitments,repeated_discussions,tasks',
            ],
            'visible_sections'   => ['nullable', 'array', $this->visibleSubsetOfSections()],
            'visible_sections.*' => [
                'string',
                'in:key_points,decisions,commitments,repeated_discussions,tasks',
            ],
            'prompt_override'    => ['nullable', 'string', 'max:10000'],
        ];
    }

    private function visibleSubsetOfSections(): ValidationRule
    {
        return new class implements ValidationRule {
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (! is_array($value)) {
                    return;
                }
                $sections = request()->input('sections', []);
                $extra = array_diff($value, is_array($sections) ? $sections : []);
                if (! empty($extra)) {
                    $fail('visible_sections must be a subset of sections.');
                }
            }
        };
    }
}
