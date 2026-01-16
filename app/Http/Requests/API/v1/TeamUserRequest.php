<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;

class TeamUserRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;
}
