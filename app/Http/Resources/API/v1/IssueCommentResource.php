<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'issue_id'   => $this->issue_id,
            'parent_id'  => $this->parent_id,
            'content'    => $this->content,
            'user'       => [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ],
            'replies'    => IssueCommentResource::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
