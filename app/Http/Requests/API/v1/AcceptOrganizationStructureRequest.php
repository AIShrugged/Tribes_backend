<?php

namespace App\Http\Requests\API\v1;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcceptOrganizationStructureRequest extends FormRequest
{
    private const DESCRIPTION_MAX_LENGTH = 20000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization'                    => ['required', 'array'],
            'organization.name'               => ['required', 'string', 'max:255'],
            'organization.description'        => ['required', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            'goals'                           => ['required', 'array', 'min:1', 'max:20'],
            'goals.*.title'                   => ['required', 'string', 'max:255'],
            'goals.*.description'             => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            'goals.*.tasks'                   => ['nullable', 'array', 'max:20'],
            'goals.*.tasks.*.title'           => ['required', 'string', 'max:255'],
            'goals.*.tasks.*.description'     => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            'goals.*.tasks.*.type'            => ['nullable', Rule::in(['development', 'organization'])],
            'goals.*.tasks.*.priority'        => ['nullable', 'integer'],
            'template'                        => ['nullable', Rule::in(Organization::TEMPLATES)],
            'team'                            => ['nullable', 'array'],
            'team.*.name'                     => ['required', 'string', 'max:255'],
            'team.*.email'                    => ['nullable', 'email', 'max:255'],
            'team.*.role'                     => ['nullable', Rule::in(['manager', 'employee'])],
        ];
    }
}
