<?php

namespace App\Http\Requests\API\v1;

use Illuminate\Foundation\Http\FormRequest;

class LinkIdentityRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'channel'    => ['required', 'string', 'exists:channels,name'],
            'identifier' => ['required', 'string', 'max:255'],
        ];
    }

    public function getChannel(): string
    {
        return $this->input('channel');
    }

    public function getIdentifier(): string
    {
        return $this->input('identifier');
    }
}
