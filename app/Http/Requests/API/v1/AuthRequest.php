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
            $data['invite'] = 'nullable|string';
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

    public function getInviteToken(): ?string
    {
        return $this->input('invite');
    }

    public function bodyParameters(): array
    {
        if ($this->route()?->getName() === 'auth.register') {
            return [
                'name'     => [
                    'description' => 'Full name of the user.',
                    'example'     => 'Alice Johnson',
                ],
                'email'    => [
                    'description' => 'Email address.',
                    'example'     => 'alice@example.com',
                ],
                'password' => [
                    'description' => 'Password (min 8 characters).',
                    'example'     => 'secret123',
                ],
                'invite'   => [
                    'description' => 'Invite token from a team invitation email.',
                    'example'     => 'abc123xyz',
                ],
            ];
        }

        return [
            'email'    => [
                'description' => 'Email address.',
                'example'     => 'alice@example.com',
            ],
            'password' => [
                'description' => 'Password.',
                'example'     => 'secret123',
            ],
        ];
    }

}
