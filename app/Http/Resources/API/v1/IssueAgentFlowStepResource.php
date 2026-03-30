<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueAgentFlowStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $agentTask = $this->resource->relationLoaded('agentTask') ? $this->agentTask : null;
        $latestRun = $agentTask?->relationLoaded('latestRun') ? $agentTask->latestRun : null;

        return [
            'id' => $this->id,
            'issue_agent_flow_id' => $this->issue_agent_flow_id,
            'agent_task_id' => $this->agent_task_id,
            'depends_on_step_id' => $this->depends_on_step_id,
            'position' => $this->position,
            'kind' => $this->kind?->value ?? $this->kind,
            'status' => $this->status?->value ?? $this->status,
            'title' => $this->title,
            'prompt' => $this->prompt,
            'definition' => $this->definition,
            'input_payload' => $this->input_payload,
            'output' => $this->output,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'task' => $agentTask ? [
                'id' => $agentTask->id,
                'status' => $latestRun?->status?->value ?? $latestRun?->status,
                'latest_run_id' => $latestRun?->id,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
