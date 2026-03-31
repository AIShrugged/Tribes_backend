<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'issue_types' => $this->resolvedIssueTypes()->map(function ($issueType) {
                return [
                    'id' => $issueType->id,
                    'organization_id' => $issueType->organization_id,
                    'key' => $issueType->key,
                    'name' => $issueType->name,
                    'base_type' => $issueType->base_type,
                    'agent_profile_id' => $issueType->agent_profile_id,
                    'agent_profile' => $issueType->agentProfile ? [
                        'id' => $issueType->agentProfile->id,
                        'name' => $issueType->agentProfile->name,
                    ] : null,
                    'metadata' => $issueType->metadata,
                    'is_active' => $issueType->is_active,
                    'created_at' => $issueType->created_at,
                    'updated_at' => $issueType->updated_at,
                ];
            })->values(),
        ];
    }
}
