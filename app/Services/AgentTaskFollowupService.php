<?php

namespace App\Services;

use App\Enums\AgentScheduleType;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use Illuminate\Support\Facades\DB;

class AgentTaskFollowupService
{
    public function createFollowupTask(AgentTask $parentTask, AgentTaskRun $originRun, array $payload): AgentTask
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        $contextSummary = trim((string) ($payload['context_summary'] ?? ''));

        if ($name === '' || $prompt === '') {
            throw new \RuntimeException('Follow-up task requires non-empty name and prompt.');
        }

        $maxDepth = (int) config('agent.agent_tasks.followups.max_depth', 5);
        $maxDelaySeconds = (int) config('agent.agent_tasks.followups.max_delay_seconds', 86400);
        $maxPerRun = (int) config('agent.agent_tasks.followups.max_per_run', 10);

        if ((int) $parentTask->followup_depth >= $maxDepth) {
            throw new \RuntimeException('Follow-up task depth limit reached.');
        }

        $existingFollowups = AgentTask::query()
            ->where('origin_agent_task_run_id', $originRun->id)
            ->count();

        if ($existingFollowups >= $maxPerRun) {
            throw new \RuntimeException('Follow-up task limit for this run has been reached.');
        }

        $delaySeconds = max(0, min($maxDelaySeconds, (int) ($payload['delay_seconds'] ?? 0)));
        $requestedAllowedTools = is_array($payload['allowed_tools'] ?? null) ? array_values($payload['allowed_tools']) : null;
        $effectiveParentTools = $parentTask->effectiveAllowedTools();
        $allowedTools = $requestedAllowedTools !== null
            ? array_values(array_intersect($requestedAllowedTools, $effectiveParentTools))
            : $effectiveParentTools;

        if ($requestedAllowedTools !== null && count($requestedAllowedTools) !== count($allowedTools)) {
            throw new \RuntimeException('Follow-up task requested tools outside the parent allowlist.');
        }

        $effectiveExecutionMode = $payload['execution_mode'] ?? $parentTask->effectiveExecutionMode()->value;
        $effectiveSandboxProfile = $payload['sandbox_profile'] ?? $parentTask->effectiveSandboxProfile();
        $inputPayload = is_array($payload['input_payload'] ?? null) ? $payload['input_payload'] : ($parentTask->input_payload ?? []);

        $followupPrompt = $prompt;
        if ($contextSummary !== '') {
            $followupPrompt .= "\n\n## Follow-up Context\n\n".$contextSummary;
        }

        return DB::transaction(function () use (
            $parentTask,
            $originRun,
            $name,
            $followupPrompt,
            $contextSummary,
            $delaySeconds,
            $allowedTools,
            $effectiveExecutionMode,
            $effectiveSandboxProfile,
            $inputPayload,
        ): AgentTask {
            $task = AgentTask::create([
                'user_id' => $parentTask->user_id,
                'organization_id' => $parentTask->organization_id,
                'team_id' => $parentTask->team_id,
                'parent_agent_task_id' => $parentTask->id,
                'origin_agent_task_run_id' => $originRun->id,
                'followup_depth' => (int) $parentTask->followup_depth + 1,
                'agent_profile_id' => $parentTask->agent_profile_id,
                'name' => $name,
                'prompt' => $followupPrompt,
                'input_payload' => $inputPayload,
                'schedule_type' => AgentScheduleType::ONE_OFF->value,
                'execution_mode' => $effectiveExecutionMode,
                'sandbox_profile' => $effectiveSandboxProfile,
                'agent_task_type' => $parentTask->agent_task_type,
                'output_mode' => $parentTask->output_mode,
                'allowed_tools' => $allowedTools,
                'allowed_outbound_hosts' => $parentTask->effectiveAllowedOutboundHosts(),
                'enabled' => true,
                'max_attempts' => $parentTask->max_attempts,
                'next_run_at' => now()->addSeconds($delaySeconds),
                'metadata' => [
                    ...($parentTask->metadata ?? []),
                    'followup' => [
                        'created_from_task_id' => $parentTask->id,
                        'created_from_run_id' => $originRun->id,
                        'context_summary' => $contextSummary,
                    ],
                ],
            ]);

            $toolCalls = data_get($originRun->metadata, 'tool_calls', []);
            $toolCalls[] = [
                'tool_name' => 'create_followup_agent_task',
                'called_at' => now()->toIso8601String(),
                'created_agent_task_id' => $task->id,
            ];

            $originRun->update([
                'metadata' => [
                    ...($originRun->metadata ?? []),
                    'tool_calls' => $toolCalls,
                ],
            ]);

            return $task;
        });
    }
}
