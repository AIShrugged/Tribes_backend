<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkspacePermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'principal_type' => $this->principal_type,
            'principal_id' => $this->principal_id,
            'can_list' => (bool) $this->can_list,
            'can_read' => (bool) $this->can_read,
            'can_write' => (bool) $this->can_write,
            'can_delete' => (bool) $this->can_delete,
            'can_execute' => (bool) $this->can_execute,
            'can_admin' => (bool) $this->can_admin,
        ];
    }
}
