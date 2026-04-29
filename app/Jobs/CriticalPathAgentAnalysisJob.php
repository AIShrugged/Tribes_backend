<?php

namespace App\Jobs;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Models\AgentTask;
use App\Models\CriticalPathGraph;
use App\Models\CriticalPathNode;
use App\Models\Issue;
use App\Services\AgentTaskSchedulerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CriticalPathAgentAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(
        public readonly ?int $teamId,
        public readonly ?int $organizationId,
    ) {}

    public function handle(
        AgentTaskSchedulerService $scheduler,
    ): void {
        $graph = CriticalPathGraph::where('team_id', $this->teamId)
            ->where('organization_id', $this->organizationId)
            ->where('status', 'ready')
            ->first();

        if (! $graph) {
            return;
        }

        $criticalNodes = CriticalPathNode::where('graph_id', $graph->id)
            ->where('node_type', 'issue')
            ->where('is_critical', true)
            ->with(['issue.assignee', 'issue.user'])
            ->orderBy('early_start')
            ->get();

        if ($criticalNodes->isEmpty()) {
            return;
        }

        foreach ($criticalNodes as $node) {
            $issue = $node->issue;

            if (! $issue) {
                continue;
            }

            // Only analyze tasks that have an assignee or creator to notify
            if (! $issue->assignee_id && ! $issue->user_id) {
                continue;
            }

            $this->dispatchAnalysisTask($issue, $node, $scheduler);
        }
    }

    private function dispatchAnalysisTask(
        Issue $issue,
        CriticalPathNode $node,
        AgentTaskSchedulerService $scheduler,
    ): void {
        $userId = $issue->user_id ?? $issue->assignee_id;

        if (! $userId) {
            return;
        }

        $prompt = $this->buildPrompt($issue, $node);

        try {
            $agentTask = AgentTask::create([
                'user_id' => $userId,
                'organization_id' => $issue->organization_id,
                'team_id' => $issue->team_id,
                'name' => "Анализ критической задачи #{$issue->id}: {$issue->name}",
                'prompt' => $prompt,
                'schedule_type' => AgentScheduleType::ONE_OFF->value,
                'execution_mode' => AgentTaskExecutionMode::INLINE->value,
                'agent_task_type' => 'background',
                'output_mode' => 'plain',
                'enabled' => true,
                'max_attempts' => 2,
                'next_run_at' => now(),
                'allowed_tools' => ['create_entity', 'update_entity'],
                'metadata' => [
                    'issue_id' => $issue->id,
                    'critical_path_node_id' => $node->id,
                    'source' => 'critical_path_analysis',
                ],
            ]);

            $scheduler->dispatchTaskNow($agentTask);
        } catch (\Throwable $e) {
            Log::error('CriticalPathAgentAnalysisJob: failed to dispatch analysis task', [
                'issue_id' => $issue->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildPrompt(Issue $issue, CriticalPathNode $node): string
    {
        $description = $issue->description
            ? "\n\nDescription: {$issue->description}"
            : '';

        $dueDate = $issue->due_date ? $issue->due_date->format('Y-m-d') : 'not set';
        $assignee = $issue->assignee?->name ?? 'unassigned';
        $duration = round($node->duration_days, 1);
        $earlyStart = round($node->early_start ?? 0, 1);

        return <<<PROMPT
You are a project manager. You are looking at an issue that is currently on the project's critical path.

Issue: {$issue->name} (ID: #{$issue->id}){$description}

Critical path parameters:
- Estimated duration: {$duration} work days
- Earliest start: {$earlyStart} days from today
- Due date: {$dueDate}
- Assignee: {$assignee}

Your actions:
1. If the issue is large (duration > 3 days), decompose it: create sub-issues via create_entity (entity type "issue") and reference the parent issue in the description.
2. Improve the issue via update_entity: add acceptance criteria, clarify context, and define the expected result.
3. Leave a comment on the issue with implementation options and a recommendation via create_entity (entity type "issue_comment").

Remember: this issue is on the critical path, so any delay delays the whole project.
PROMPT;
    }
}
