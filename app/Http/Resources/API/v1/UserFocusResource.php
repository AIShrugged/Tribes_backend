<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserFocusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'focus_text' => $this->content['focus_text'] ?? null,
            'deadline'   => $this->content['deadline'] ?? null,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}