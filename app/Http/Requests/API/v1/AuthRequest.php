<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class AuthRequest extends FormRequest
{
    public function rules(): array
    {
        $data = [
            'email'    => 'required|email',
            'password' => 'required|string',
        ];

        if ($this->route()->getName() === 'auth.register') {
            $data['name'] = 'required|string';
        }

        return $data;
    }

    public function getEmail(): string
    {
        return $this->input('email');
    }

    public function getPass(): string
    {
        return $this->input('password');
    }
}
