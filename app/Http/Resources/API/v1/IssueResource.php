<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\API\v1\IssueAttachmentResource;

class IssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'code' => $this->code,
            'status' => $this->status,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'issue_type_id' => $this->issue_type_id,
            'issue_type' => $this->whenLoaded('issueType', fn () => [
                'id' => $this->issueType->id,
                'key' => $this->issueType->key,
                'name' => $this->issueType->name,
                'base_type' => $this->issueType->base_type,
                'agent_profile_id' => $this->issueType->agent_profile_id,
            ]),
            'organization_id' => $this->organization_id,
            'team_id' => $this->team_id,
            'sourceable_type' => $this->sourceable_type,
            'sourceable_id' => $this->sourceable_id,
            'agent_task_id' => $this->agent_task_id,
            'agent_task_run' => $this->when($this->relationLoaded('agentTask') && $this->agentTask, function () {
                $run = $this->agentTask->relationLoaded('latestRun') ? $this->agentTask->latestRun : null;

                return $run ? [
                    'id' => $run->id,
                    'status' => $run->status?->value ?? $run->status,
                    'current_tool' => data_get($run->metadata, 'current_tool'),
                    'current_tool_description' => data_get($run->metadata, 'current_tool_description'),
                ] : null;
            }),
            'agent_flow_id' => $this->issue_agent_flow_id,
            'agent_flow' => $this->whenLoaded('agentFlow', fn () => IssueAgentFlowResource::make($this->agentFlow)),
            'epic_id' => $this->epic_id,
            'epic' => $this->whenLoaded('epic', fn () => $this->epic ? [
                'id' => $this->epic->id,
                'name' => $this->epic->name,
                'status' => $this->epic->status,
            ] : null),
            'child_issues' => $this->whenLoaded('childIssues', fn () => IssueResource::collection($this->childIssues)),
            'assignee_id' => $this->assignee_id,
            'assignee' => $this->whenLoaded('assignee', fn () => UserResource::make($this->assignee)),
            'user_id' => $this->user_id,
            'author' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'due_date' => $this->due_date?->toDateString(),
            'priority' => $this->priority ?? 0,
            'registration_date' => $this->registration_date,
            'close_date' => $this->close_date,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
            'attachments' => IssueAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
