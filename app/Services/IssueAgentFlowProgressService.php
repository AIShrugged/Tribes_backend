<?php

namespace App\Services;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Enums\ConversationChannelType;
use App\Enums\IssueAgentFlowStatus;
use App\Enums\IssueAgentFlowStepKind;
use App\Enums\IssueAgentFlowStepStatus;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Issue;
use App\Models\IssueAgentFlow;
use App\Models\IssueAgentFlowStep;
use App\Services\Channel\ChannelRuntimeService;
use App\Services\Channel\UserChannelTargetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IssueAgentFlowProgressService
{
    public function __construct(
        private readonly AgentTaskSchedulerService $scheduler,
        private readonly UserChannelTargetResolver $userChannelTargetResolver,
        private readonly ChannelRuntimeService $channelRuntimeService,
    ) {}

    public function handleTaskCompleted(AgentTask $task, AgentTaskRun $run): void
    {
        $step = $this->findStepByTaskId($task->id);
        if (! $step) {
            return;
        }

        try {
            if ($step->kind === IssueAgentFlowStepKind::VALIDATION) {
                $this->completeValidationStep($step, $run);

                return;
            }

            if ($step->kind === IssueAgentFlowStepKind::PLANNING) {
                $this->completePlanningStep($step, $run);

                return;
            }

            if ($step->kind === IssueAgentFlowStepKind::REVIEW) {
                $this->completeReviewStep($step, $run);

                return;
            }

            if ($step->status === IssueAgentFlowStepStatus::SUCCEEDED) {
                return;
            }

            $this->completeExecutionStep($step, $run);
        } catch (\Throwable $e) {
            $this->markWorkflowBlocked(
                $step,
                $e->getMessage(),
                $run->output,
                markStepFailed: in_array($step->kind, [
                    IssueAgentFlowStepKind::PLANNING,
                    IssueAgentFlowStepKind::VALIDATION,
                    IssueAgentFlowStepKind::REVIEW,
                ], true),
            );
        }
    }

    public function handleTaskFailed(AgentTask $task, AgentTaskRun $run): void
    {
        $step = $this->findStepByTaskId($task->id);
        if (! $step) {
            return;
        }

        DB::transaction(function () use ($step, $run): void {
            $flow = IssueAgentFlow::query()->lockForUpdate()->find($step->issue_agent_flow_id);
            if (! $flow) {
                return;
            }

            $step->update([
                'status' => IssueAgentFlowStepStatus::FAILED->value,
                'output' => $run->output,
                'error_message' => $run->error_message,
                'finished_at' => now(),
            ]);

            $flow->update([
                'status' => IssueAgentFlowStatus::BLOCKED->value,
                'last_error' => $run->error_message,
            ]);
        });
    }

    private function completeValidationStep(IssueAgentFlowStep $validationStep, AgentTaskRun $run): void
    {
        $result = $this->decodeJsonOutput($run->output ?? '');

        if ($result === null || ($result['valid'] ?? false) === true) {
            // valid (or unparseable — fallback to valid per risk mitigation strategy)
            if ($result === null) {
                Log::warning('IssueAgentFlow: validation step returned non-JSON output, treating as valid.', [
                    'step_id' => $validationStep->id,
                    'output' => mb_substr($run->output ?? '', 0, 500),
                ]);
            }

            DB::transaction(function () use ($validationStep, $run): void {
                $flow = IssueAgentFlow::query()->lockForUpdate()->find($validationStep->issue_agent_flow_id);
                if (! $flow) {
                    return;
                }

                $validationStep->update([
                    'status' => IssueAgentFlowStepStatus::SUCCEEDED->value,
                    'output' => $run->output,
                    'finished_at' => now(),
                    'error_message' => null,
                ]);
            });

            $this->dispatchPlanningStep($validationStep);

            return;
        }

        // valid: false — questions need answering
        $questions = $result['questions'] ?? [];

        $flow = DB::transaction(function () use ($validationStep, $run): IssueAgentFlow {
            $flow = IssueAgentFlow::query()->lockForUpdate()->find($validationStep->issue_agent_flow_id);
            if (! $flow) {
                throw new \RuntimeException('Issue agent flow not found.');
            }

            $validationStep->update([
                'status' => IssueAgentFlowStepStatus::WAITING_FOR_USER->value,
                'output' => $run->output,
                'finished_at' => now(),
            ]);

            $flow->update([
                'status' => IssueAgentFlowStatus::WAITING_FOR_USER->value,
            ]);

            return $flow;
        });

        // Notify OUTSIDE the transaction to avoid holding locks during HTTP calls
        $issue = $flow->issue()->first();
        if ($issue && $questions !== []) {
            $this->notifyOwnerWithQuestions($flow, $issue, $questions);
        }
    }

    private function dispatchPlanningStep(IssueAgentFlowStep $validationStep): void
    {
        $planningStep = IssueAgentFlowStep::query()
            ->where('issue_agent_flow_id', $validationStep->issue_agent_flow_id)
            ->where('kind', IssueAgentFlowStepKind::PLANNING->value)
            ->where('status', IssueAgentFlowStepStatus::PENDING->value)
            ->orderBy('position')
            ->first();

        if (! $planningStep || ! $planningStep->agent_task_id) {
            return;
        }

        $planningStep->update(['status' => IssueAgentFlowStepStatus::QUEUED->value]);

        $task = AgentTask::query()->find($planningStep->agent_task_id);
        if (! $task) {
            return;
        }

        $task->update(['enabled' => true, 'next_run_at' => now()]);

        $run = $this->scheduler->dispatchTaskNow($task);
        if (! $run) {
            $flow = IssueAgentFlow::query()->find($validationStep->issue_agent_flow_id);
            if ($flow) {
                $flow->update([
                    'status' => IssueAgentFlowStatus::BLOCKED->value,
                    'last_error' => 'Failed to dispatch planning task after validation.',
                ]);
            }
        }
    }

    private function notifyOwnerWithQuestions(IssueAgentFlow $flow, Issue $issue, array $questions): void
    {
        try {
            $owner = $flow->user()->first();
            if (! $owner) {
                return;
            }

            $questionList = implode("\n", array_map(
                static fn (string $q, int $i) => ($i + 1) . '. ' . $q,
                $questions,
                array_keys($questions),
            ));

            $message = "[Tribes] Задача «{$issue->name}» требует уточнений перед запуском агента:\n\n{$questionList}\n\nПожалуйста, дополни описание задачи и повтори запуск.";

            $conversation = $this->userChannelTargetResolver->resolve($owner, ConversationChannelType::TELEGRAM);
            if ($conversation) {
                $this->channelRuntimeService->deliverToConversation($conversation, $message);

                return;
            }

            // Fallback to web chat
            $conversation = $this->userChannelTargetResolver->resolve($owner, ConversationChannelType::WEB_CHAT);
            if ($conversation) {
                $this->channelRuntimeService->deliverToConversation($conversation, $message);
            }
        } catch (\Throwable $e) {
            Log::error('IssueAgentFlow: failed to notify owner with validation questions.', [
                'flow_id' => $flow->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function completePlanningStep(IssueAgentFlowStep $planningStep, AgentTaskRun $run): ?IssueAgentFlow
    {
        $flow = DB::transaction(function () use ($planningStep, $run) {
            $flow = IssueAgentFlow::query()->lockForUpdate()->find($planningStep->issue_agent_flow_id);
            if (! $flow) {
                throw new \RuntimeException('Issue agent flow not found.');
            }

            $planningStep->refresh();

            if ($planningStep->status === IssueAgentFlowStepStatus::SUCCEEDED) {
                return null;
            }

            $plan = $this->parsePlan($run->output ?? '');
            $steps = $plan['steps'];

            if ($steps === []) {
                throw new \RuntimeException('Planner did not return any execution steps.');
            }

            // Delete any existing execution and review steps (handles retries)
            $flow->steps()
                ->whereIn('kind', [
                    IssueAgentFlowStepKind::EXECUTION->value,
                    IssueAgentFlowStepKind::REVIEW->value,
                ])
                ->where('position', '>', $planningStep->position)
                ->delete();

            $planCriticProfile = AgentProfile::query()->where('key', 'plan-critic')->first();

            // Positions: planning=1, review(plan)=2 (if critic exists), execution=3+
            $executionOffset = $planningStep->position + 1;
            $reviewStep = null;

            if ($planCriticProfile) {
                $reviewTask = $this->createReviewTask($flow, $planCriticProfile, 'plan', $run->output, $planningStep->position + 1);

                $reviewStep = $flow->steps()->create([
                    'agent_task_id' => $reviewTask->id,
                    'position' => $planningStep->position + 1,
                    'kind' => IssueAgentFlowStepKind::REVIEW->value,
                    'title' => 'Review plan',
                    'prompt' => $reviewTask->prompt,
                    'definition' => ['kind' => 'review', 'review_kind' => 'plan'],
                    'input_payload' => $reviewTask->input_payload,
                    'status' => IssueAgentFlowStepStatus::QUEUED->value,
                    'depends_on_step_id' => $planningStep->id,
                ]);

                $executionOffset = $planningStep->position + 2;
            }

            $previousStep = $planningStep;
            foreach (array_values($steps) as $index => $stepDefinition) {
                $position = $executionOffset + $index;

                $createdStep = $flow->steps()->create([
                    'position' => $position,
                    'kind' => IssueAgentFlowStepKind::EXECUTION->value,
                    'title' => $stepDefinition['title'],
                    'prompt' => $stepDefinition['prompt'],
                    'definition' => $stepDefinition,
                    'input_payload' => [
                        'flow' => [
                            'issue_agent_flow_id' => $flow->id,
                            'issue_id' => $flow->issue_id,
                            'current_step_position' => $position,
                        ],
                        'previous_step' => [
                            'id' => $previousStep->id,
                            'title' => $previousStep->title,
                            'output' => $index === 0 ? $run->output : null,
                        ],
                    ],
                    'status' => IssueAgentFlowStepStatus::PENDING->value,
                    'depends_on_step_id' => $previousStep->id,
                ]);

                $previousStep = $createdStep;
            }

            $planningStep->update([
                'status' => IssueAgentFlowStepStatus::SUCCEEDED->value,
                'output' => $run->output,
                'finished_at' => now(),
                'error_message' => null,
            ]);

            $nextPosition = $reviewStep ? $reviewStep->position : $executionOffset;

            $flow->update([
                'status' => IssueAgentFlowStatus::RUNNING->value,
                'current_step_position' => $nextPosition,
                'plan_output' => $run->output,
                'last_error' => null,
            ]);

            return $flow;
        });

        if ($flow) {
            $this->dispatchNextStep($flow, $flow->current_step_position);
        }

        return $flow;
    }

    private function completeExecutionStep(IssueAgentFlowStep $completedStep, AgentTaskRun $run): void
    {
        $flow = DB::transaction(function () use ($completedStep, $run): IssueAgentFlow {
            $flow = IssueAgentFlow::query()->lockForUpdate()->find($completedStep->issue_agent_flow_id);
            if (! $flow) {
                throw new \RuntimeException('Issue agent flow not found.');
            }

            $completedStep->update([
                'status' => IssueAgentFlowStepStatus::SUCCEEDED->value,
                'output' => $run->output,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $nextStep = $flow->steps()
                ->where('kind', IssueAgentFlowStepKind::EXECUTION->value)
                ->where('position', '>', $completedStep->position)
                ->where('status', IssueAgentFlowStepStatus::PENDING->value)
                ->orderBy('position')
                ->first();

            if ($nextStep) {
                $flow->update([
                    'status' => IssueAgentFlowStatus::RUNNING->value,
                    'current_step_position' => $nextStep->position,
                    'last_error' => null,
                ]);

                return $flow;
            }

            // No more execution steps — insert result-critic REVIEW if profile exists
            $resultCriticProfile = AgentProfile::query()->where('key', 'result-critic')->first();

            if ($resultCriticProfile) {
                $reviewPosition = $completedStep->position + 1;
                $reviewTask = $this->createReviewTask($flow, $resultCriticProfile, 'result', $run->output, $reviewPosition);

                $flow->steps()->create([
                    'agent_task_id' => $reviewTask->id,
                    'position' => $reviewPosition,
                    'kind' => IssueAgentFlowStepKind::REVIEW->value,
                    'title' => 'Review result',
                    'prompt' => $reviewTask->prompt,
                    'definition' => ['kind' => 'review', 'review_kind' => 'result'],
                    'input_payload' => $reviewTask->input_payload,
                    'status' => IssueAgentFlowStepStatus::QUEUED->value,
                    'depends_on_step_id' => $completedStep->id,
                ]);

                $flow->update([
                    'status' => IssueAgentFlowStatus::RUNNING->value,
                    'current_step_position' => $reviewPosition,
                    'last_error' => null,
                ]);

                return $flow;
            }

            $flow->update([
                'status' => IssueAgentFlowStatus::COMPLETED->value,
                'current_step_position' => null,
                'last_error' => null,
            ]);

            $flow->issue()->update(['status' => 'done']);

            return $flow;
        });

        $this->dispatchNextStep($flow, $flow->current_step_position);
    }

    private function completeReviewStep(IssueAgentFlowStep $reviewStep, AgentTaskRun $run): void
    {
        $definition = is_array($reviewStep->definition) ? $reviewStep->definition : [];
        $reviewKind = $definition['review_kind'] ?? 'plan';

        if ($reviewKind === 'plan') {
            $this->completePlanReviewStep($reviewStep, $run);
        } else {
            $this->completeResultReviewStep($reviewStep, $run);
        }
    }

    private function completePlanReviewStep(IssueAgentFlowStep $reviewStep, AgentTaskRun $run): void
    {
        $result = $this->decodeJsonOutput($run->output ?? '');
        $approved = $result === null || ($result['approved'] ?? false) === true;

        if ($result === null) {
            Log::warning('IssueAgentFlow: plan-critic returned non-JSON output, treating as approved.', [
                'step_id' => $reviewStep->id,
            ]);
        }

        if ($approved) {
            $flow = DB::transaction(function () use ($reviewStep, $run): IssueAgentFlow {
                $flow = IssueAgentFlow::query()->lockForUpdate()->find($reviewStep->issue_agent_flow_id);
                if (! $flow) {
                    throw new \RuntimeException('Issue agent flow not found.');
                }

                $reviewStep->update([
                    'status' => IssueAgentFlowStepStatus::SUCCEEDED->value,
                    'output' => $run->output,
                    'finished_at' => now(),
                    'error_message' => null,
                ]);

                $firstExecution = $flow->steps()
                    ->where('kind', IssueAgentFlowStepKind::EXECUTION->value)
                    ->where('status', IssueAgentFlowStepStatus::PENDING->value)
                    ->orderBy('position')
                    ->first();

                $nextPosition = $firstExecution?->position;

                $flow->update([
                    'current_step_position' => $nextPosition,
                    'last_error' => null,
                ]);

                return $flow;
            });

            $this->dispatchNextStep($flow, $flow->current_step_position);

            return;
        }

        // Not approved — check attempt count
        $gaps = $result['gaps'] ?? [];
        $reason = $result['reason'] ?? 'Plan rejected by critic.';

        /** @var array{flow: IssueAgentFlow, retry_task: ?AgentTask} */
        $txResult = DB::transaction(function () use ($reviewStep, $run, $gaps, $reason): array {
            $flow = IssueAgentFlow::query()->lockForUpdate()->find($reviewStep->issue_agent_flow_id);
            if (! $flow) {
                throw new \RuntimeException('Issue agent flow not found.');
            }

            $reviewStep->update([
                'status' => IssueAgentFlowStepStatus::SUCCEEDED->value,
                'output' => $run->output,
                'finished_at' => now(),
                'error_message' => null,
            ]);

            $metadata = is_array($flow->metadata) ? $flow->metadata : [];
            $attempts = (int) ($metadata['plan_critic_attempts'] ?? 0) + 1;
            $metadata['plan_critic_attempts'] = $attempts;
            $flow->update(['metadata' => $metadata]);

            if ($attempts >= 2) {
                Log::info('IssueAgentFlow: plan-critic rejected plan but max attempts reached, proceeding.', [
                    'flow_id' => $flow->id,
                    'reason' => $reason,
                    'gaps' => $gaps,
                ]);

                $firstExecution = $flow->steps()
                    ->where('kind', IssueAgentFlowStepKind::EXECUTION->value)
                    ->where('status', IssueAgentFlowStepStatus::PENDING->value)
                    ->orderBy('position')
                    ->first();

                $flow->update(['current_step_position' => $firstExecution?->position]);

                return ['flow' => $flow, 'retry_task' => null];
            }

            // Retrigger planning: delete execution and current review steps, re-queue planning
            $planningStep = $flow->steps()
                ->where('kind', IssueAgentFlowStepKind::PLANNING->value)
                ->orderBy('position')
                ->first();

            if ($planningStep) {
                $flow->steps()
                    ->whereIn('kind', [
                        IssueAgentFlowStepKind::EXECUTION->value,
                        IssueAgentFlowStepKind::REVIEW->value,
                    ])
                    ->where('position', '>', $planningStep->position)
                    ->delete();

                // Update planning prompt to include gaps feedback
                $gapsFeedback = $gaps !== [] ? "\n\n## Feedback from plan critic\n\nThe previous plan was rejected. Issues to fix:\n- " . implode("\n- ", $gaps) : '';
                $newPrompt = $planningStep->prompt . $gapsFeedback;

                $planningTask = AgentTask::query()->find($planningStep->agent_task_id);
                if ($planningTask) {
                    // Create a new task with updated prompt for retry
                    $retryTask = AgentTask::create([
                        'user_id' => $planningTask->user_id,
                        'organization_id' => $planningTask->organization_id,
                        'team_id' => $planningTask->team_id,
                        'agent_profile_id' => $planningTask->agent_profile_id,
                        'name' => $planningTask->name . ' (retry)',
                        'prompt' => $newPrompt,
                        'schedule_type' => AgentScheduleType::ONE_OFF->value,
                        'execution_mode' => $planningTask->execution_mode,
                        'agent_task_type' => 'background',
                        'output_mode' => 'plain',
                        'allowed_tools' => [],
                        'allowed_outbound_hosts' => [],
                        'enabled' => true,
                        'max_attempts' => 3,
                        'next_run_at' => now(),
                        'input_payload' => $planningTask->input_payload,
                        'metadata' => $planningTask->metadata,
                    ]);

                    $planningStep->update([
                        'agent_task_id' => $retryTask->id,
                        'status' => IssueAgentFlowStepStatus::QUEUED->value,
                        'output' => null,
                        'error_message' => null,
                        'finished_at' => null,
                        'prompt' => $newPrompt,
                    ]);

                    $flow->update([
                        'status' => IssueAgentFlowStatus::PLANNING->value,
                        'current_step_position' => $planningStep->position,
                        'plan_output' => null,
                    ]);

                    return ['flow' => $flow, 'retry_task' => $retryTask];
                }
            }

            // Fallback: proceed to execution
            $firstExecution = $flow->steps()
                ->where('kind', IssueAgentFlowStepKind::EXECUTION->value)
                ->where('status', IssueAgentFlowStepStatus::PENDING->value)
                ->orderBy('position')
                ->first();

            $flow->update(['current_step_position' => $firstExecution?->position]);

            return ['flow' => $flow, 'retry_task' => null];
        });

        $flow = $txResult['flow'];
        $retryTask = $txResult['retry_task'];

        if ($retryTask) {
            // Dispatch the replanning task directly — dispatchNextStep cannot handle PLANNING steps
            $this->scheduler->dispatchTaskNow($retryTask);
        } else {
            $this->dispatchNextStep($flow, $flow->current_step_position);
        }
    }

    private function completeResultReviewStep(IssueAgentFlowStep $reviewStep, AgentTaskRun $run): void
    {
        DB::transaction(function () use ($reviewStep, $run): void {
            $flow = IssueAgentFlow::query()->lockForUpdate()->find($reviewStep->issue_agent_flow_id);
            if (! $flow) {
                return;
            }

            $reviewStep->update([
                'status' => IssueAgentFlowStepStatus::SUCCEEDED->value,
                'output' => $run->output,
                'finished_at' => now(),
                'error_message' => null,
            ]);

            $flow->update([
                'status' => IssueAgentFlowStatus::COMPLETED->value,
                'current_step_position' => null,
                'last_error' => null,
            ]);

            $flow->issue()->update(['status' => 'done']);
        });
        // result-critic notifies the owner via send_user_message tool in its own execution
    }

    private function dispatchNextStep(IssueAgentFlow $flow, ?int $position): void
    {
        if ($position === null) {
            return;
        }

        // First check for a REVIEW step at this position
        $reviewStep = $flow->steps()
            ->where('position', $position)
            ->where('kind', IssueAgentFlowStepKind::REVIEW->value)
            ->where('status', IssueAgentFlowStepStatus::QUEUED->value)
            ->first();

        if ($reviewStep && $reviewStep->agent_task_id) {
            $task = AgentTask::query()->find($reviewStep->agent_task_id);
            if ($task) {
                $run = $this->scheduler->dispatchTaskNow($task);
                if (! $run) {
                    $flow->update([
                        'status' => IssueAgentFlowStatus::BLOCKED->value,
                        'last_error' => 'Failed to dispatch review task.',
                    ]);
                }
            }

            return;
        }

        // Otherwise look for an EXECUTION step
        $step = $flow->steps()
            ->where('position', $position)
            ->where('kind', IssueAgentFlowStepKind::EXECUTION->value)
            ->where('status', IssueAgentFlowStepStatus::PENDING->value)
            ->first();

        if (! $step) {
            return;
        }

        $previousStep = $step->dependsOnStep()->first();
        $previousOutput = $previousStep?->output;

        $task = $this->createExecutionTask($flow, $step, $previousOutput);

        $step->update([
            'agent_task_id' => $task->id,
            'status' => IssueAgentFlowStepStatus::QUEUED->value,
        ]);

        $flow->issue()->update([
            'agent_task_id' => $task->id,
        ]);

        $run = $this->scheduler->dispatchTaskNow($task);
        if (! $run) {
            $flow->update([
                'status' => IssueAgentFlowStatus::BLOCKED->value,
                'last_error' => 'Failed to dispatch issue flow execution task.',
            ]);

            return;
        }
    }

    private function createReviewTask(IssueAgentFlow $flow, AgentProfile $profile, string $reviewKind, ?string $subjectOutput, int $position): AgentTask
    {
        $issue = $flow->issue()->first();
        if (! $issue) {
            throw new \RuntimeException('Issue not found for flow.');
        }

        $prompt = $reviewKind === 'plan'
            ? $this->buildPlanReviewPrompt($issue, $subjectOutput)
            : $this->buildResultReviewPrompt($issue, $subjectOutput);

        return AgentTask::create([
            'user_id' => $flow->user_id,
            'organization_id' => $flow->organization_id,
            'team_id' => $flow->team_id,
            'agent_profile_id' => $profile->id,
            'name' => "Issue #{$issue->id} review ({$reviewKind}) at step {$position}",
            'prompt' => $prompt,
            'schedule_type' => AgentScheduleType::ONE_OFF->value,
            'agent_task_type' => 'background',
            'output_mode' => 'plain',
            'allowed_tools' => is_array($profile->allowed_tools) ? $profile->allowed_tools : [],
            'allowed_outbound_hosts' => [],
            'enabled' => true,
            'max_attempts' => 2,
            'next_run_at' => now(),
            'input_payload' => [
                'flow' => [
                    'issue_agent_flow_id' => $flow->id,
                    'issue_id' => $flow->issue_id,
                ],
                'issue' => [
                    'id' => $issue->id,
                    'name' => $issue->name,
                    'description' => $issue->description,
                ],
                'review_kind' => $reviewKind,
                'subject_output' => $subjectOutput,
            ],
            'metadata' => [
                'issue_agent_flow_id' => $flow->id,
                'issue_id' => $issue->id,
                'flow_kind' => 'review',
                'review_kind' => $reviewKind,
                'flow_step_position' => $position,
            ],
        ]);
    }

    private function buildPlanReviewPrompt(Issue $issue, ?string $planOutput): string
    {
        $description = $issue->description ? "\n\nОписание:\n{$issue->description}" : '';
        $plan = $planOutput ?? '(нет)';

        return <<<PROMPT
Ты строгий критик планов (пессимист). Твоя задача — найти дыры в плане до того, как он уйдёт в исполнение.

## Исходная задача

- ID: {$issue->id}
- Название: {$issue->name}{$description}

## Сгенерированный план

{$plan}

## Что проверить

1. Все ли шаги ведут к цели задачи?
2. Нет ли пропущенных шагов (например, забытые тесты, проверки, уведомления)?
3. Есть ли у каждого шага чёткие критерии завершения?
4. Логичен ли порядок шагов?

## Вывод

Верни ТОЛЬКО JSON без пояснений:

Если план приемлем:
{"approved": true}

Если есть проблемы:
{"approved": false, "reason": "Краткое резюме проблемы", "gaps": ["Конкретная проблема 1", "Конкретная проблема 2"]}

Правила:
- Не добавляй markdown, пояснений или других ключей вне JSON.
- Максимум 3 пункта в gaps.
- Будь конкретным.
PROMPT;
    }

    private function buildResultReviewPrompt(Issue $issue, ?string $executionOutput): string
    {
        $description = $issue->description ? "\n\nОписание:\n{$issue->description}" : '';
        $result = $executionOutput ?? '(нет)';

        return <<<PROMPT
Ты строгий приёмщик результатов. Сравни что было сделано с тем, что требовалось.

## Исходная задача

- ID: {$issue->id}
- Название: {$issue->name}{$description}

## Результат исполнения

{$result}

## Что проверить

1. Соответствует ли результат требованиям задачи?
2. Есть ли недоделанные части?
3. Есть ли ссылки на PR, артефакты или другие подтверждения выполнения?

## Действие

После оценки — отправь постановщику задачи сообщение через send_user_message (канал: telegram) с итогом.

Формат сообщения:
- Если done: «[Tribes] Задача «{название}» выполнена. {краткое резюме}»
- Если partial: «[Tribes] Задача «{название}» выполнена частично. Не закрыто: {список}»
- Если failed: «[Tribes] Задача «{название}» не выполнена. {причина}»

## Вывод

После отправки сообщения верни ТОЛЬКО JSON:
{"verdict": "done", "summary": "..."}
// или
{"verdict": "partial", "summary": "...", "gaps": ["..."]}
// или
{"verdict": "failed", "summary": "...", "gaps": ["..."]}

Правила:
- Не добавляй markdown, пояснений или других ключей вне JSON.
PROMPT;
    }

    private function createExecutionTask(IssueAgentFlow $flow, IssueAgentFlowStep $step, ?string $previousOutput): AgentTask
    {
        $issue = $flow->issue()->first();
        if (! $issue) {
            throw new \RuntimeException('Issue not found for flow.');
        }

        $definition = is_array($step->definition) ? $step->definition : [];
        $outputMode = (string) ($definition['output_mode'] ?? 'md');
        $outputMode = in_array($outputMode, ['md', 'plain'], true) ? $outputMode : 'md';
        $profile = $flow->profile;

        $inputPayload = [
            'flow' => [
                'issue_agent_flow_id' => $flow->id,
                'issue_id' => $flow->issue_id,
                'status' => $flow->status?->value ?? $flow->status,
                'current_step_position' => $step->position,
            ],
            'step' => [
                'id' => $step->id,
                'position' => $step->position,
                'kind' => $step->kind?->value ?? $step->kind,
                'title' => $step->title,
                'prompt' => $step->prompt,
                'definition' => $definition,
            ],
            'previous_step_output' => $previousOutput,
            'issue' => [
                'id' => $issue->id,
                'name' => $issue->name,
                'description' => $issue->description,
                'type' => $issue->type,
                'status' => $issue->status,
            ],
        ];

        if ($profile && is_array($profile->metadata)) {
            $inputPayload['profile_metadata'] = $profile->metadata;
        }

        return AgentTask::create([
            'user_id' => $flow->user_id,
            'organization_id' => $flow->organization_id,
            'team_id' => $flow->team_id,
            'agent_profile_id' => $flow->agent_profile_id,
            'name' => "Issue #{$issue->id} step {$step->position}: {$step->title}",
            'prompt' => $this->buildExecutionPrompt($issue, $flow, $step, $previousOutput),
            'schedule_type' => AgentScheduleType::ONE_OFF->value,
            'execution_mode' => $flow->agent_profile_id ? null : AgentTaskExecutionMode::INLINE->value,
            'agent_task_type' => 'background',
            'output_mode' => $outputMode,
            'enabled' => true,
            'max_attempts' => 3,
            'next_run_at' => now(),
            'input_payload' => $inputPayload,
            'metadata' => [
                'profile_metadata' => is_array($profile?->metadata) ? $profile->metadata : [],
                'issue_agent_flow_id' => $flow->id,
                'issue_id' => $issue->id,
                'flow_kind' => 'execution',
                'flow_step_id' => $step->id,
                'max_iterations' => 20,
            ],
        ]);
    }

    private function buildExecutionPrompt(Issue $issue, IssueAgentFlow $flow, IssueAgentFlowStep $step, ?string $previousOutput): string
    {
        $previousSection = $previousOutput ? "\n\n## Previous Step Output\n\n{$previousOutput}" : '';

        return <<<PROMPT
You are executing step {$step->position} of an issue development flow.

## Issue

- ID: {$issue->id}
- Title: {$issue->name}
- Type: {$issue->type}
- Status: {$issue->status}

## Step

- Title: {$step->title}
- Instructions: {$step->prompt}

## Git Branch

All execution steps for this issue **must use a single shared branch**: `feature/issue-{$issue->id}`.

- If the branch does not exist yet, create it from `dev`: `git checkout dev && git pull && git checkout -b feature/issue-{$issue->id}`
- If the branch already exists, switch to it: `git checkout feature/issue-{$issue->id} && git pull`
- Commit and push your changes to `feature/issue-{$issue->id}` — never to a different branch.

## Repository Exploration

Read files selectively to stay within context limits:

- Start with structure (`ls`, `find`) — do not read files you haven't identified as relevant.
- Never read `vendor/`, `node_modules/`, `storage/`, `bootstrap/cache/`.
- Read only the specific classes, methods, or sections you need — not entire files when one method suffices.
- If a file is large, grep for the relevant function/class first, then read only that range.

## Requirements

- Treat the previous step output as the input for this step.
- Complete only this step. Do not start later steps.
- When you finish, summarize the step output clearly for the next step in the chain.

{$previousSection}
PROMPT;
    }

    private function findStepByTaskId(int $taskId): ?IssueAgentFlowStep
    {
        return IssueAgentFlowStep::query()
            ->where('agent_task_id', $taskId)
            ->with('flow.issue')
            ->first();
    }

    private function parsePlan(string $output): array
    {
        $decoded = $this->decodePlannerOutput($output);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Planner output is not valid JSON.');
        }

        $steps = $decoded['steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            throw new \RuntimeException('Planner output must include a non-empty steps array.');
        }

        $normalizedSteps = [];
        foreach (array_values($steps) as $index => $step) {
            if (! is_array($step)) {
                throw new \RuntimeException('Planner step definitions must be JSON objects.');
            }

            $title = trim((string) ($step['title'] ?? $step['name'] ?? ''));
            $prompt = trim((string) ($step['prompt'] ?? $step['instructions'] ?? $step['description'] ?? ''));

            if ($title === '' || $prompt === '') {
                throw new \RuntimeException('Planner step definitions require title and prompt.');
            }

            $normalizedSteps[] = [
                'title' => $title,
                'prompt' => $prompt,
                'acceptance_criteria' => array_values(array_filter(array_map(
                    static fn ($item) => is_string($item) ? trim($item) : '',
                    is_array($step['acceptance_criteria'] ?? null) ? $step['acceptance_criteria'] : []
                ))),
                'output_mode' => in_array(($step['output_mode'] ?? 'md'), ['md', 'plain'], true) ? $step['output_mode'] : 'md',
                'position' => $index + 1,
            ];
        }

        return [
            'goal' => trim((string) ($decoded['goal'] ?? '')),
            'steps' => $normalizedSteps,
        ];
    }

    private function decodeJsonOutput(string $output): ?array
    {
        return $this->decodePlannerOutput($output);
    }

    private function decodePlannerOutput(string $output): ?array
    {
        $candidates = [];

        $trimmed = trim($output);
        if ($trimmed !== '') {
            $candidates[] = $this->stripJsonCodeFence($trimmed);
            $candidates[] = $this->extractJsonObject($trimmed);

            // Handle sandbox runner prefix: "Agent returned a non-standard final response: {json}\nCompleted..."
            // Strip known prefixes first, then extract JSON with balanced-brace matching.
            $prefixes = [
                'Agent returned a non-standard final response: ',
                'Agent returned a non-normalizable final response: ',
            ];
            foreach ($prefixes as $prefix) {
                if (str_starts_with($trimmed, $prefix)) {
                    $afterPrefix = substr($trimmed, strlen($prefix));
                    $candidates[] = trim($afterPrefix);
                    $candidates[] = $this->extractJsonObject($afterPrefix);
                    $candidates[] = $this->extractBalancedJson($afterPrefix);
                }
            }

            // Also try balanced extraction on the full output (handles nested JSON better)
            $candidates[] = $this->extractBalancedJson($trimmed);
        }

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $decoded = json_decode(trim($candidate), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function stripJsonCodeFence(string $output): string
    {
        $json = preg_replace('/^```(?:json)?\s*/i', '', $output) ?? $output;
        $json = preg_replace('/\s*```$/', '', $json) ?? $json;

        return trim($json);
    }

    private function extractJsonObject(string $output): ?string
    {
        $start = strpos($output, '{');
        $end = strrpos($output, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($output, $start, $end - $start + 1);
    }

    /**
     * Extract a top-level JSON object by tracking balanced braces.
     *
     * Unlike extractJsonObject (which takes first '{' to last '}'),
     * this finds the first '{' and walks forward counting braces,
     * handling strings with escaped characters correctly.
     */
    private function extractBalancedJson(string $output): ?string
    {
        $start = strpos($output, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($output);

        for ($i = $start; $i < $len; $i++) {
            $char = $output[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($output, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function markWorkflowBlocked(IssueAgentFlowStep $step, string $errorMessage, ?string $output = null, bool $markStepFailed = false): void
    {
        DB::transaction(function () use ($step, $errorMessage, $output, $markStepFailed): void {
            $flow = IssueAgentFlow::query()->lockForUpdate()->find($step->issue_agent_flow_id);
            if (! $flow) {
                return;
            }

            if ($markStepFailed) {
                $step->update([
                    'status' => IssueAgentFlowStepStatus::FAILED->value,
                    'output' => $output ?? $step->output,
                    'error_message' => $errorMessage,
                    'finished_at' => now(),
                ]);
            }

            $flow->update([
                'status' => IssueAgentFlowStatus::BLOCKED->value,
                'last_error' => $errorMessage,
            ]);
        });
    }
}
