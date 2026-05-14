<?php

namespace App\Http\Requests\API\v1;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateOrganizationStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description'  => ['nullable', 'string', 'max:10000'],
            'upload_token' => ['nullable', 'string', 'regex:/^[0-9a-f-]{36}$/i'],
            'links'        => ['nullable', 'array', 'max:5'],
            'links.*'      => ['string', 'url', 'max:2048'],
            'template'     => ['nullable', Rule::in(Organization::TEMPLATES)],
        ];
    }
}
