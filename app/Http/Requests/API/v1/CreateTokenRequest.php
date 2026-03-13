<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class CreateTokenRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }

    public function getName(): string
    {
        return $this->input('name');
    }

    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Token name.',
                'example'     => 'my-api-key',
            ],
        ];
    }
}
