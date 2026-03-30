<?php

namespace App\Services;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Enums\IssueAgentFlowStatus;
use App\Enums\IssueAgentFlowStepKind;
use App\Enums\IssueAgentFlowStepStatus;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Issue;
use App\Models\IssueAgentFlow;
use App\Models\IssueAgentFlowStep;
use Illuminate\Support\Facades\DB;

class IssueAgentFlowProgressService
{
    public function __construct(
        private readonly AgentTaskSchedulerService $scheduler,
    ) {}

    public function handleTaskCompleted(AgentTask $task, AgentTaskRun $run): void
    {
        $step = $this->findStepByTaskId($task->id);
        if (! $step) {
            return;
        }

        try {
            if ($step->kind === IssueAgentFlowStepKind::PLANNING) {
                $this->completePlanningStep($step, $run);

                return;
            }

            if ($step->status === IssueAgentFlowStepStatus::SUCCEEDED) {
                return;
            }

            $this->completeExecutionStep($step, $run);
        } catch (\Throwable $e) {
            $this->markWorkflowBlocked($step, $e->getMessage(), $run->output, markStepFailed: $step->kind === IssueAgentFlowStepKind::PLANNING);
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

            $flow->steps()
                ->where('kind', IssueAgentFlowStepKind::EXECUTION->value)
                ->delete();

            $previousStep = $planningStep;
            foreach (array_values($steps) as $index => $stepDefinition) {
                $position = $index + 1;

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

            $flow->update([
                'status' => IssueAgentFlowStatus::RUNNING->value,
                'current_step_position' => 1,
                'plan_output' => $run->output,
                'last_error' => null,
            ]);

            return $flow;
        });

        if ($flow) {
            $this->dispatchNextStep($flow, 1);
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

            if (! $nextStep) {
                $flow->update([
                    'status' => IssueAgentFlowStatus::COMPLETED->value,
                    'current_step_position' => null,
                    'last_error' => null,
                ]);

                return $flow;
            }

            $flow->update([
                'status' => IssueAgentFlowStatus::RUNNING->value,
                'current_step_position' => $nextStep->position,
                'last_error' => null,
            ]);

            return $flow;
        });

        $this->dispatchNextStep($flow, $flow->current_step_position);
    }

    private function dispatchNextStep(IssueAgentFlow $flow, ?int $position): void
    {
        if ($position === null) {
            return;
        }

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

    private function createExecutionTask(IssueAgentFlow $flow, IssueAgentFlowStep $step, ?string $previousOutput): AgentTask
    {
        $issue = $flow->issue()->first();
        if (! $issue) {
            throw new \RuntimeException('Issue not found for flow.');
        }

        $definition = is_array($step->definition) ? $step->definition : [];
        $outputMode = (string) ($definition['output_mode'] ?? 'md');
        $outputMode = in_array($outputMode, ['md', 'plain'], true) ? $outputMode : 'md';

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
            'input_payload' => [
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
            ],
            'metadata' => [
                'issue_agent_flow_id' => $flow->id,
                'issue_id' => $issue->id,
                'flow_kind' => 'execution',
                'flow_step_id' => $step->id,
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

    private function decodePlannerOutput(string $output): ?array
    {
        $candidates = [];

        $trimmed = trim($output);
        if ($trimmed !== '') {
            $candidates[] = $this->stripJsonCodeFence($trimmed);
            $candidates[] = $this->extractJsonObject($trimmed);
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
