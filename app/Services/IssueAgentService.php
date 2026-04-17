<?php

namespace App\Services;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Issue;
use App\Models\User;

class IssueAgentService
{
    public function __construct(
        private readonly IssueAgentFlowService $flowService,
        private readonly AgentTaskSchedulerService $scheduler,
    ) {
    }

    public function dispatch(Issue $issue, User $user, ?int $agentProfileId = null): AgentTaskRun
    {
        if ($issue->isDevelopment()) {
            return $this->flowService->start($issue, $user, $agentProfileId);
        }

        if ($issue->agent_task_id) {
            $activeRun = AgentTaskRun::query()
                ->where('agent_task_id', $issue->agent_task_id)
                ->whereIn('status', ['queued', 'processing'])
                ->exists();

            if ($activeRun) {
                throw new \App\Exceptions\AppException(
                    'Agent task is already running for this issue.',
                    'ISSUE_AGENT_TASK_ALREADY_RUNNING',
                    409,
                );
            }
        }

        $task = $this->createTask($issue, $user, $agentProfileId);

        $issue->update(['agent_task_id' => $task->id, 'status' => 'in_progress']);

        $run = $this->scheduler->dispatchTaskNow($task);

        if (!$run) {
            throw new \App\Exceptions\AppException(
                'Failed to dispatch agent task.',
                'AGENT_TASK_DISPATCH_FAILED',
                500,
            );
        }

        return $run;
    }

    private function createTask(Issue $issue, User $user, ?int $agentProfileId): AgentTask
    {
        $prompt = $this->buildPrompt($issue);
        $resolvedAgentProfileId = $issue->effectiveAgentProfileId($agentProfileId);
        $profile = $resolvedAgentProfileId ? AgentProfile::query()->find($resolvedAgentProfileId) : null;

        return AgentTask::create([
            'user_id'          => $user->id,
            'organization_id'  => $issue->organization_id,
            'team_id'          => $issue->team_id,
            'name'             => "Issue #{$issue->id}: {$issue->name}",
            'prompt'           => $prompt,
            'agent_profile_id' => $resolvedAgentProfileId,
            'schedule_type'    => AgentScheduleType::ONE_OFF->value,
            'execution_mode'   => $resolvedAgentProfileId ? null : AgentTaskExecutionMode::INLINE->value,
            'agent_task_type'  => 'background',
            'enabled'          => true,
            'next_run_at'      => now(),
            'input_payload'    => $this->buildInputPayload($issue, $profile),
            'metadata'         => $this->buildTaskMetadata($profile),
        ]);
    }

    private function buildInputPayload(Issue $issue, ?AgentProfile $profile): array
    {
        $payload = [
            'issue_id' => $issue->id,
            'issue_type' => $issue->type,
            'organization_id' => $issue->organization_id,
            'team_id' => $issue->team_id,
        ];

        if ($profile && is_array($profile->metadata)) {
            $payload['profile_metadata'] = $profile->metadata;
        }

        if ($issue->pr_number) {
            $payload['pr_number'] = $issue->pr_number;
        }

        if ($issue->pr_url) {
            $payload['pr_url'] = $issue->pr_url;
        }

        if ($issue->pr_repository) {
            $payload['pr_repository'] = $issue->pr_repository;
        }

        // Pass repository config from issue type metadata so agents can auto-detect the right repo
        $issueTypeMetadata = $issue->issueType?->metadata;
        if (is_string($issueTypeMetadata)) {
            $issueTypeMetadata = json_decode($issueTypeMetadata, true);
        }
        if (is_array($issueTypeMetadata)) {
            $payload['issue_type_metadata'] = $issueTypeMetadata;
        }

        return $payload;
    }

    private function buildTaskMetadata(?AgentProfile $profile): array
    {
        return [
            'profile_metadata' => is_array($profile?->metadata) ? $profile->metadata : [],
        ];
    }

    private function buildPrompt(Issue $issue): string
    {
        $issueType = $issue->issueType;
        $typeLabel = $issueType?->name ?? $issue->type;
        $parts = [
            "## Задача",
            "**Название:** {$issue->name}",
            "**Тип:** {$typeLabel}",
            "**Статус:** {$issue->status}",
        ];

        if ($issue->description) {
            $parts[] = "**Описание:**\n{$issue->description}";
        }

        if ($issue->assignee_name || $issue->assignee?->name) {
            $parts[] = "**Назначено:** " . ($issue->assignee?->name ?? $issue->assignee_name);
        }

        if ($issue->due_date) {
            $parts[] = "**Дедлайн:** {$issue->due_date->format('Y-m-d')}";
        }

        $parts[] = "";
        $parts[] = "Выполни эту задачу. Используй доступные инструменты для исследования контекста и выполнения работы. После завершения обнови статус задачи.";

        return implode("\n", $parts);
    }
}
