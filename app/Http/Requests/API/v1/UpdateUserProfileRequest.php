<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use Illuminate\Validation\Validator;

class UpdateUserProfileRequest extends ApiResourceRequest
{
    public function updateRules(): array
    {
        return [
            'name'             => ['sometimes', 'string', 'min:1', 'max:255'],
            'current_password' => ['required_with:password', 'string'],
            'password'         => ['sometimes', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (!$this->filled('name') && !$this->filled('password')) {
                $v->errors()->add('name', 'At least one of name or password must be provided.');
            }
        });
    }

    public function getName(): ?string
    {
        return $this->input('name');
    }

    public function getCurrentPassword(): ?string
    {
        return $this->input('current_password');
    }

    public function getPassword(): ?string
    {
        return $this->input('password');
    }

    public function bodyParameters(): array
    {
        return [
            'name'             => [
                'description' => 'New display name. Required if password is not provided.',
                'example'     => 'Alice Johnson',
            ],
            'current_password' => [
                'description' => 'Current password. Required when changing the password.',
                'example'     => 'oldpassword123',
            ],
            'password'         => [
                'description' => 'New password. Minimum 8 characters. Required if name is not provided.',
                'example'     => 'newpassword123',
            ],
            'password_confirmation' => [
                'description' => 'Must match the password field.',
                'example'     => 'newpassword123',
            ],
        ];
    }
}
