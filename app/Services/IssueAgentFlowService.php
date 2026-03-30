<?php

namespace App\Services;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Enums\IssueAgentFlowStatus;
use App\Enums\IssueAgentFlowStepKind;
use App\Enums\IssueAgentFlowStepStatus;
use App\Exceptions\AppException;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Issue;
use App\Models\IssueAgentFlow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class IssueAgentFlowService
{
    public function __construct(
        private readonly AgentTaskSchedulerService $scheduler,
    ) {}

    public function start(Issue $issue, User $user, ?int $agentProfileId = null): AgentTaskRun
    {
        if ($issue->type !== Issue::TYPE_DEVELOPMENT) {
            throw new AppException(
                'Development issue flow is only available for issues with type "development".',
                'ISSUE_AGENT_FLOW_UNSUPPORTED_TYPE',
                422,
            );
        }

        $existingFlow = IssueAgentFlow::query()->where('issue_id', $issue->id)->first();
        if ($existingFlow) {
            throw new AppException(
                'Issue development flow already exists for this issue.',
                'ISSUE_AGENT_FLOW_ALREADY_EXISTS',
                409,
            );
        }

        [$flow, $plannerTask] = DB::transaction(function () use ($issue, $user, $agentProfileId): array {
            $flow = IssueAgentFlow::create([
                'issue_id' => $issue->id,
                'user_id' => $user->id,
                'organization_id' => $issue->organization_id,
                'team_id' => $issue->team_id,
                'agent_profile_id' => $agentProfileId,
                'status' => IssueAgentFlowStatus::PLANNING->value,
                'metadata' => [
                    'issue_type' => $issue->type,
                    'issue_name' => $issue->name,
                ],
            ]);

            $plannerTask = AgentTask::create([
                'user_id' => $user->id,
                'organization_id' => $issue->organization_id,
                'team_id' => $issue->team_id,
                'agent_profile_id' => null,
                'name' => "Plan development flow for issue #{$issue->id}: {$issue->name}",
                'prompt' => $this->buildPlanningPrompt($issue),
                'schedule_type' => AgentScheduleType::ONE_OFF->value,
                'execution_mode' => AgentTaskExecutionMode::INLINE->value,
                'agent_task_type' => 'background',
                'output_mode' => 'plain',
                'allowed_tools' => [],
                'allowed_outbound_hosts' => [],
                'enabled' => true,
                'max_attempts' => 3,
                'next_run_at' => now(),
                'input_payload' => $this->buildPlanningInputPayload($issue, $flow),
                'metadata' => [
                    'issue_agent_flow_id' => $flow->id,
                    'issue_id' => $issue->id,
                    'flow_kind' => 'planning',
                    'response_contract' => 'issue_agent_flow_plan',
                ],
            ]);

            $flow->steps()->create([
                'agent_task_id' => $plannerTask->id,
                'position' => 0,
                'kind' => IssueAgentFlowStepKind::PLANNING->value,
                'title' => 'Planning',
                'prompt' => $plannerTask->prompt,
                'definition' => [
                    'kind' => 'planning',
                ],
                'input_payload' => $plannerTask->input_payload,
                'status' => IssueAgentFlowStepStatus::QUEUED->value,
            ]);

            $issue->update([
                'agent_task_id' => $plannerTask->id,
                'issue_agent_flow_id' => $flow->id,
            ]);

            return [$flow, $plannerTask];
        });

        $run = $this->scheduler->dispatchTaskNow($plannerTask);

        if (! $run) {
            throw new AppException(
                'Failed to dispatch issue development planning task.',
                'ISSUE_AGENT_FLOW_DISPATCH_FAILED',
                500,
            );
        }

        return $run;
    }

    public function buildPlanningPrompt(Issue $issue): string
    {
        $description = $issue->description ? "\n\nОписание:\n{$issue->description}" : '';
        $assignee = $issue->assignee?->name ?? $issue->assignee_name;
        $assigneeBlock = $assignee ? "\nНазначено: {$assignee}" : '';
        $dueDate = $issue->due_date ? "\nДедлайн: {$issue->due_date->format('Y-m-d')}" : '';

        return <<<PROMPT
Ты planning-агент для development issue flow. Сначала сформируй четкий план, который потом будет исполнен отдельными агентскими задачами по одной.

## Issue

- ID: {$issue->id}
- Title: {$issue->name}
- Type: {$issue->type}
- Status: {$issue->status}{$assigneeBlock}{$dueDate}

{$description}

## Output Contract

Return ONLY valid JSON object with this shape:
{
  "goal": "short description of the end result",
  "steps": [
    {
      "title": "short step title",
      "prompt": "clear execution instructions for the next agent",
      "acceptance_criteria": ["optional", "array", "of", "checks"],
      "output_mode": "plain"
    }
  ]
}

Rules:
- Create between 2 and 7 steps.
- Each step must depend on the previous step output.
- Keep each step small, explicit, and sequential.
- Do not add markdown fences, explanation, or extra keys outside the JSON object.
- If the task needs code changes, make sure the later steps assume the earlier output is available as input.
PROMPT;
    }

    public function buildPlanningInputPayload(Issue $issue, IssueAgentFlow $flow): array
    {
        return [
            'flow' => [
                'issue_agent_flow_id' => $flow->id,
                'issue_id' => $issue->id,
                'issue_type' => $issue->type,
            ],
            'issue' => [
                'id' => $issue->id,
                'name' => $issue->name,
                'description' => $issue->description,
                'type' => $issue->type,
                'status' => $issue->status,
                'organization_id' => $issue->organization_id,
                'team_id' => $issue->team_id,
            ],
        ];
    }
}
