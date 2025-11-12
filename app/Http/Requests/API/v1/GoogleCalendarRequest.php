<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class GoogleCalendarRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string'],
            'code'  => ['required', 'string'],
        ];
    }

    public function getState(): string
    {
        return $this->input('state');
    }

    public function getCode(): string
    {
        return $this->input('code');
    }
}
