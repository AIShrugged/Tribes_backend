<?php

namespace App\Services;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Models\Issue;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
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

        $task = $this->createTask($issue, $user, $agentProfileId);

        $issue->update(['agent_task_id' => $task->id]);

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

        return AgentTask::create([
            'user_id'          => $user->id,
            'organization_id'  => $issue->organization_id,
            'team_id'          => $issue->team_id,
            'name'             => "Issue #{$issue->id}: {$issue->name}",
            'prompt'           => $prompt,
            'agent_profile_id' => $agentProfileId,
            'schedule_type'    => AgentScheduleType::ONE_OFF->value,
            'execution_mode'   => AgentTaskExecutionMode::INLINE->value,
            'agent_task_type'  => 'background',
            'enabled'          => true,
            'next_run_at'      => now(),
        ]);
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
