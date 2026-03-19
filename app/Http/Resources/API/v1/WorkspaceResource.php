<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'team_id' => $this->team_id,
            'owner_user_id' => $this->owner_user_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'scope_type' => $this->scope_type,
            'root_prefix' => $this->root_prefix,
            'storage_disk' => $this->storage_disk,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'permissions' => WorkspacePermissionResource::collection($this->whenLoaded('permissions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
