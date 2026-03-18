<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->whenLoaded('profile');
        $latestRun = $this->whenLoaded('latestRun');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'agent_profile_id' => $this->agent_profile_id,
            'profile' => $profile ? [
                'id' => $profile->id,
                'key' => $profile->key,
                'name' => $profile->name,
            ] : null,
            'name' => $this->name,
            'prompt' => $this->prompt,
            'input_payload' => $this->input_payload,
            'schedule_type' => $this->schedule_type?->value,
            'interval_seconds' => $this->interval_seconds,
            'execution_mode' => $this->execution_mode?->value,
            'effective_execution_mode' => $this->effectiveExecutionMode()->value,
            'sandbox_profile' => $this->sandbox_profile,
            'effective_sandbox_profile' => $this->effectiveSandboxProfile(),
            'agent_task_type' => $this->agent_task_type,
            'output_mode' => $this->output_mode,
            'allowed_tools' => $this->allowed_tools,
            'effective_allowed_tools' => $this->effectiveAllowedTools(),
            'allowed_outbound_hosts' => $this->allowed_outbound_hosts,
            'effective_allowed_outbound_hosts' => $this->effectiveAllowedOutboundHosts(),
            'enabled' => $this->enabled,
            'max_attempts' => $this->max_attempts,
            'next_run_at' => $this->next_run_at,
            'last_run_at' => $this->last_run_at,
            'last_completed_at' => $this->last_completed_at,
            'last_failed_at' => $this->last_failed_at,
            'last_error' => $this->last_error,
            'locked_at' => $this->locked_at,
            'latest_run' => $latestRun ? [
                'id' => $latestRun->id,
                'status' => $latestRun->status?->value ?? $latestRun->status,
                'attempt' => $latestRun->attempt,
                'scheduled_for' => $latestRun->scheduled_for,
                'started_at' => $latestRun->started_at,
                'finished_at' => $latestRun->finished_at,
                'error_message' => $latestRun->error_message,
                'metadata' => [
                    'sandbox_result' => data_get($latestRun->metadata, 'sandbox_result'),
                    'sandbox' => data_get($latestRun->metadata, 'sandbox'),
                    'tool_calls' => data_get($latestRun->metadata, 'tool_calls', []),
                    'llm_calls' => data_get($latestRun->metadata, 'llm_calls', []),
                ],
            ] : null,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
