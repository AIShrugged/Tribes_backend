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
        $persistentWorkspace = data_get($this->metadata, 'persistent_workspace', []);
        $networkPolicy = data_get($this->metadata, 'network_policy', []);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'organization_id' => $this->organization_id,
            'team_id' => $this->team_id,
            'parent_agent_task_id' => $this->parent_agent_task_id,
            'origin_agent_task_run_id' => $this->origin_agent_task_run_id,
            'followup_depth' => $this->followup_depth,
            'agent_profile_id' => $this->agent_profile_id,
            'profile' => $profile ? [
                'id' => $profile->id,
                'key' => $profile->key,
                'name' => $profile->name,
                'description' => $profile->description,
                'config_schema' => $profile->config_schema,
                'task_payload_schema' => $profile->task_payload_schema,
                'execution_mode' => $profile->execution_mode?->value,
                'sandbox_profile' => $profile->sandbox_profile,
                'allowed_tools' => $profile->allowed_tools,
                'allowed_outbound_hosts' => $profile->allowed_outbound_hosts,
                'default_model' => $profile->default_model,
                'enabled' => $profile->enabled,
                'metadata' => $profile->metadata,
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
            'persistent_workspace' => [
                'enabled' => (bool) data_get($persistentWorkspace, 'enabled', false),
                'key' => data_get($persistentWorkspace, 'key'),
            ],
            'effective_persistent_workspace' => [
                'enabled' => $this->usesPersistentSandboxWorkspace(),
                'key' => $this->persistentSandboxWorkspaceKey(),
            ],
            'network_policy' => [
                'restrict_hosts' => (bool) data_get($networkPolicy, 'restrict_hosts', true),
            ],
            'effective_network_policy' => [
                'restrict_hosts' => $this->restrictsOutboundHosts(),
            ],
            'metadata_schema' => self::metadataSchema(),
            'latest_run' => $latestRun ? [
                'id' => $latestRun->id,
                'paperclip_issue_id' => $latestRun->paperclip_issue_id,
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
                'activity' => $latestRun->relationLoaded('activityLogs')
                    ? $latestRun->activityLogs->map(fn ($log) => [
                        'id'          => $log->id,
                        'tool_name'   => $log->tool_name,
                        'description' => $log->description,
                        'tool_result' => $log->tool_result,
                        'success'     => $log->success,
                        'created_at'  => $log->created_at,
                    ])->values()
                    : null,
            ] : null,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public static function metadataSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => true,
            'properties' => [
                'max_iterations' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Maximum sandbox-agent iterations for a single run.',
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Maximum runtime for a single task execution in seconds.',
                ],
                'persistent_workspace' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'enabled' => [
                            'type' => 'boolean',
                            'description' => 'Whether the task reuses a persistent sandbox workspace across runs.',
                        ],
                        'key' => [
                            'type' => 'string',
                            'description' => 'Optional stable workspace key. If omitted, backend derives it from the payload.',
                        ],
                    ],
                ],
                'network_policy' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'restrict_hosts' => [
                            'type' => 'boolean',
                            'description' => 'Whether outbound network access is limited to allowed_outbound_hosts.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
