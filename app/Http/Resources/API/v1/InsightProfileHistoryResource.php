<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsightProfileHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'insight_profile_id' => $this->insight_profile_id,
            'category'           => $this->category->value,
            'content'            => $this->content,
            'version'            => $this->version,
            'created_at'         => $this->created_at,
        ];
    }
}
