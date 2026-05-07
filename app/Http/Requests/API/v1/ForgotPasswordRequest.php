<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
        ];
    }

    public function getEmail(): string
    {
        return $this->input('email');
    }
}
