<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class TeamInviteRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function storeRules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    public function getEmail(): string
    {
        return $this->input('email');
    }
}