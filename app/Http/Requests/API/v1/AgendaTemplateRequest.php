<?php

namespace App\Http\Requests\API\v1;

use App\Models\AgendaTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AgendaTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sections'   => ['required', 'array', 'min:1'],
            'sections.*' => ['string', Rule::in(AgendaTemplate::DEFAULT_SECTIONS)],
        ];
    }
}
