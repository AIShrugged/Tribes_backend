<?php

namespace App\Services;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Enums\IssueAgentFlowStatus;
use App\Enums\IssueAgentFlowStepKind;
use App\Enums\IssueAgentFlowStepStatus;
use App\Exceptions\AppException;
use App\Models\AgentProfile;
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
        if (! $issue->isDevelopment()) {
            throw new AppException(
                'Development issue flow is only available for frontend or backend issues.',
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

        [$flow, $firstTask, $firstStep] = DB::transaction(function () use ($issue, $user, $agentProfileId): array {
            $resolvedAgentProfileId = $issue->effectiveAgentProfileId($agentProfileId);
            $profile = $resolvedAgentProfileId ? AgentProfile::query()->find($resolvedAgentProfileId) : null;

            $flow = IssueAgentFlow::create([
                'issue_id' => $issue->id,
                'user_id' => $user->id,
                'organization_id' => $issue->organization_id,
                'team_id' => $issue->team_id,
                'agent_profile_id' => $resolvedAgentProfileId,
                'status' => IssueAgentFlowStatus::PLANNING->value,
                'metadata' => [
                    'issue_type' => $issue->type,
                    'issue_type_id' => $issue->issue_type_id,
                    'issue_name' => $issue->name,
                ],
            ]);

            $validatorProfile = AgentProfile::query()->where('key', 'task-validator')->first();

            $plannerTask = AgentTask::create([
                'user_id' => $user->id,
                'organization_id' => $issue->organization_id,
                'team_id' => $issue->team_id,
                'agent_profile_id' => $resolvedAgentProfileId,
                'name' => "Plan development flow for issue #{$issue->id}: {$issue->name}",
                'prompt' => $this->buildPlanningPrompt($issue),
                'schedule_type' => AgentScheduleType::ONE_OFF->value,
                'execution_mode' => $resolvedAgentProfileId ? null : AgentTaskExecutionMode::INLINE->value,
                'agent_task_type' => 'background',
                'output_mode' => 'plain',
                'allowed_tools' => [],
                'allowed_outbound_hosts' => [],
                'enabled' => true,
                'max_attempts' => 3,
                'next_run_at' => now(),
                'input_payload' => $this->buildPlanningInputPayload($issue, $flow, $profile),
                'metadata' => [
                    'profile_metadata' => is_array($profile?->metadata) ? $profile->metadata : [],
                    'issue_agent_flow_id' => $flow->id,
                    'issue_id' => $issue->id,
                    'flow_kind' => 'planning',
                    'response_contract' => 'issue_agent_flow_plan',
                ],
            ]);

            $planningStep = $flow->steps()->create([
                'agent_task_id' => $plannerTask->id,
                'position' => 1,
                'kind' => IssueAgentFlowStepKind::PLANNING->value,
                'title' => 'Planning',
                'prompt' => $plannerTask->prompt,
                'definition' => ['kind' => 'planning'],
                'input_payload' => $plannerTask->input_payload,
                'status' => IssueAgentFlowStepStatus::PENDING->value,
            ]);

            // If task-validator profile exists, prepend a VALIDATION step
            if ($validatorProfile) {
                $validatorTask = AgentTask::create([
                    'user_id' => $user->id,
                    'organization_id' => $issue->organization_id,
                    'team_id' => $issue->team_id,
                    'agent_profile_id' => $validatorProfile->id,
                    'name' => "Validate issue #{$issue->id}: {$issue->name}",
                    'prompt' => $this->buildValidationPrompt($issue),
                    'schedule_type' => AgentScheduleType::ONE_OFF->value,
                    'agent_task_type' => 'background',
                    'output_mode' => 'plain',
                    'allowed_tools' => [],
                    'allowed_outbound_hosts' => [],
                    'enabled' => true,
                    'max_attempts' => 2,
                    'next_run_at' => now(),
                    'input_payload' => $this->buildValidationInputPayload($issue, $flow),
                    'metadata' => [
                        'issue_agent_flow_id' => $flow->id,
                        'issue_id' => $issue->id,
                        'flow_kind' => 'validation',
                    ],
                ]);

                $validationStep = $flow->steps()->create([
                    'agent_task_id' => $validatorTask->id,
                    'position' => 0,
                    'kind' => IssueAgentFlowStepKind::VALIDATION->value,
                    'title' => 'Validate issue quality',
                    'prompt' => $validatorTask->prompt,
                    'definition' => ['kind' => 'validation'],
                    'input_payload' => $validatorTask->input_payload,
                    'status' => IssueAgentFlowStepStatus::QUEUED->value,
                ]);

                $planningStep->update([
                    'depends_on_step_id' => $validationStep->id,
                ]);

                $issue->update([
                    'agent_task_id' => $validatorTask->id,
                    'issue_agent_flow_id' => $flow->id,
                ]);

                return [$flow, $validatorTask, $validationStep];
            }

            // No validator profile — start with planning directly (backward-compatible)
            $planningStep->update(['status' => IssueAgentFlowStepStatus::QUEUED->value]);

            $issue->update([
                'agent_task_id' => $plannerTask->id,
                'issue_agent_flow_id' => $flow->id,
            ]);

            return [$flow, $plannerTask, $planningStep];
        });

        $run = $this->scheduler->dispatchTaskNow($firstTask);

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
        Ты planning-агент для issue flow по frontend/backend задаче. Сначала сформируй четкий план, который потом будет исполнен отдельными агентскими задачами по одной.

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

    /**
     * Resume a flow that is waiting for user answers to validation questions.
     * Updates the issue description with the provided answers and dispatches the PLANNING step directly.
     */
    public function answer(Issue $issue, string $answers): void
    {
        $flow = IssueAgentFlow::query()
            ->where('issue_id', $issue->id)
            ->where('status', IssueAgentFlowStatus::WAITING_FOR_USER->value)
            ->first();

        if (! $flow) {
            throw new \App\Exceptions\AppException(
                'No flow in WAITING_FOR_USER state found for this issue.',
                'ISSUE_AGENT_FLOW_NOT_WAITING',
                422,
            );
        }

        // Append answers to issue description
        $updatedDescription = trim(($issue->description ?? '') . "\n\n## Ответы на уточняющие вопросы\n\n" . $answers);
        $issue->update(['description' => $updatedDescription]);

        // Reset the validation step
        \App\Models\IssueAgentFlowStep::query()
            ->where('issue_agent_flow_id', $flow->id)
            ->where('kind', IssueAgentFlowStepKind::VALIDATION->value)
            ->where('status', IssueAgentFlowStepStatus::WAITING_FOR_USER->value)
            ->update(['status' => IssueAgentFlowStepStatus::SUCCEEDED->value]);

        // Update flow status back to PLANNING and dispatch the planning step
        $planningStep = $flow->steps()
            ->where('kind', IssueAgentFlowStepKind::PLANNING->value)
            ->where('status', IssueAgentFlowStepStatus::PENDING->value)
            ->orderBy('position')
            ->first();

        if (! $planningStep || ! $planningStep->agent_task_id) {
            throw new \App\Exceptions\AppException(
                'Planning step not found for flow.',
                'ISSUE_AGENT_FLOW_PLANNING_STEP_MISSING',
                500,
            );
        }

        // Rebuild planning prompt and payload with updated issue description
        $issue->refresh();
        $newPrompt = $this->buildPlanningPrompt($issue);
        $newPayload = $this->buildPlanningInputPayload($issue, $flow);

        $planningStep->update([
            'status' => IssueAgentFlowStepStatus::QUEUED->value,
            'prompt' => $newPrompt,
            'input_payload' => $newPayload,
        ]);

        $flow->update(['status' => IssueAgentFlowStatus::PLANNING->value]);

        // Create a fresh planning task with updated prompt (old task has stale description)
        $oldTask = AgentTask::query()->find($planningStep->agent_task_id);
        if (! $oldTask) {
            return;
        }

        $freshTask = AgentTask::create([
            'user_id' => $oldTask->user_id,
            'organization_id' => $oldTask->organization_id,
            'team_id' => $oldTask->team_id,
            'agent_profile_id' => $oldTask->agent_profile_id,
            'name' => $oldTask->name,
            'prompt' => $newPrompt,
            'schedule_type' => AgentScheduleType::ONE_OFF->value,
            'execution_mode' => $oldTask->execution_mode,
            'agent_task_type' => 'background',
            'output_mode' => 'plain',
            'allowed_tools' => [],
            'allowed_outbound_hosts' => [],
            'enabled' => true,
            'max_attempts' => 3,
            'next_run_at' => now(),
            'input_payload' => $newPayload,
            'metadata' => $oldTask->metadata,
        ]);

        $planningStep->update(['agent_task_id' => $freshTask->id]);
        $this->scheduler->dispatchTaskNow($freshTask);
    }

    public function buildValidationPrompt(Issue $issue): string
    {
        $description = $issue->description ? "\n\nОписание:\n{$issue->description}" : '';
        $assignee = $issue->assignee?->name ?? $issue->assignee_name;
        $assigneeBlock = $assignee ? "\nИсполнитель: {$assignee}" : '';
        $dueDate = $issue->due_date ? "\nДедлайн: {$issue->due_date->format('Y-m-d')}" : '';

        return <<<PROMPT
Ты валидатор качества задач (SMART/DoD). Проверь задачу и реши — достаточно ли в ней информации для её выполнения.

## Задача

- ID: {$issue->id}
- Название: {$issue->name}
- Тип: {$issue->type}{$assigneeBlock}{$dueDate}

{$description}

## Критерии проверки

1. Есть ли чёткий ожидаемый результат (что считается "сделано")?
2. Понятен ли масштаб — что входит в задачу, а что нет?
3. Назначен ли исполнитель?
4. Реалистичен ли дедлайн (если указан)?

## Вывод

Верни ТОЛЬКО JSON без пояснений:

Если задача понятна:
{"valid": true}

Если не хватает информации:
{"valid": false, "questions": ["Конкретный вопрос 1?", "Конкретный вопрос 2?"]}

Правила:
- Максимум 3 вопроса.
- Вопросы должны быть конкретными, не абстрактными.
- Не задавай вопросов, ответы на которые уже есть в описании.
- Не добавляй markdown, пояснений или других ключей вне JSON.
PROMPT;
    }

    public function buildValidationInputPayload(Issue $issue, IssueAgentFlow $flow): array
    {
        return [
            'flow' => [
                'issue_agent_flow_id' => $flow->id,
                'issue_id' => $issue->id,
            ],
            'issue' => [
                'id' => $issue->id,
                'name' => $issue->name,
                'description' => $issue->description,
                'type' => $issue->type,
                'status' => $issue->status,
                'assignee_name' => $issue->assignee_name,
                'due_date' => $issue->due_date?->format('Y-m-d'),
            ],
        ];
    }

    public function buildPlanningInputPayload(Issue $issue, IssueAgentFlow $flow, ?AgentProfile $profile = null): array
    {
        $payload = [
            'flow' => [
                'issue_agent_flow_id' => $flow->id,
                'issue_id' => $issue->id,
                'issue_type' => $issue->type,
                'issue_type_id' => $issue->issue_type_id,
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

        if ($profile && is_array($profile->metadata)) {
            $payload['profile_metadata'] = $profile->metadata;
        }

        return $payload;
    }
}
