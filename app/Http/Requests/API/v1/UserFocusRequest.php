<?php

namespace App\Http\Requests\API\v1;

use App\Http\Requests\API\ApiResourceRequest;

class UserFocusRequest extends ApiResourceRequest
{
    public function updateRules(): array
    {
        return [
            'focus_text' => ['required', 'string', 'min:1', 'max:500'],
            'deadline'   => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function getFocusText(): string
    {
        return strip_tags(trim($this->input('focus_text')));
    }

    public function getDeadline(): ?string
    {
        return $this->input('deadline');
    }
}