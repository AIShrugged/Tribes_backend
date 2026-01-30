<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;
use App\Traits\PaginatedRequestTrait;
use Illuminate\Foundation\Http\FormRequest;

class FollowupRequest extends ApiResourceRequest
{
    use PaginatedRequestTrait;

    public function getCalendarEventId(): int
    {
        return $this->input('calendar_event_id');
    }

    public function getFollowupId(): int
    {
        return $this->input('followup_id');
    }
}
