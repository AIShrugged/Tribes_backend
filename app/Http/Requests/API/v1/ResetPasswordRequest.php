<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token'                 => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ];
    }

    public function getToken(): string
    {
        return $this->input('token');
    }

    public function getPassword(): string
    {
        return $this->input('password');
    }
}
