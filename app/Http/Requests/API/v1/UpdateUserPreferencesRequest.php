<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'menu'                      => ['nullable', 'array'],
            'menu.primary'              => ['nullable', 'array'],
            'menu.primary.*.id'         => ['required', 'string'],
            'menu.primary.*.visible'    => ['required', 'boolean'],
            'menu.secondary'            => ['nullable', 'array'],
            'menu.secondary.*.id'       => ['required', 'string'],
            'menu.secondary.*.visible'  => ['required', 'boolean'],
        ];
    }

    public function getPreferences(): array
    {
        return $this->only(['menu']);
    }
}