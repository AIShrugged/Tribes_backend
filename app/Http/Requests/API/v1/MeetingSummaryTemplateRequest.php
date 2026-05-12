<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class MeetingSummaryTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sections'   => ['required', 'array', 'min:1'],
            'sections.*' => [
                'string',
                'in:key_points,decisions,commitments,repeated_discussions,tasks',
            ],
        ];
    }
}
