<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsightItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'profile_id'  => $this->profile_id,
            'category'    => $this->category->value,
            'fact'        => $this->fact,
            'confidence'  => $this->confidence,
            'is_archived' => $this->is_archived,
            'source_id'   => $this->insight_source_id,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
