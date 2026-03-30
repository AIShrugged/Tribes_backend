<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueAgentFlowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $steps = $this->whenLoaded('steps');

        return [
            'id' => $this->id,
            'issue_id' => $this->issue_id,
            'user_id' => $this->user_id,
            'organization_id' => $this->organization_id,
            'team_id' => $this->team_id,
            'agent_profile_id' => $this->agent_profile_id,
            'status' => $this->status?->value ?? $this->status,
            'current_step_position' => $this->current_step_position,
            'plan_output' => $this->plan_output,
            'last_error' => $this->last_error,
            'metadata' => $this->metadata,
            'steps' => $steps ? IssueAgentFlowStepResource::collection($steps) : [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
