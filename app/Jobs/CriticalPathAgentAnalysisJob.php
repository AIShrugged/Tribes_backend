<?php

namespace App\Jobs;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Models\AgentTask;
use App\Models\CriticalPathGraph;
use App\Models\CriticalPathNode;
use App\Models\Issue;
use App\Services\AgentTaskSchedulerService;
use App\Services\CriticalPath\CriticalPathNotificationService;
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
        CriticalPathNotificationService $notificationService,
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

            $this->dispatchAnalysisTask($issue, $node, $scheduler, $notificationService);
        }
    }

    private function dispatchAnalysisTask(
        Issue $issue,
        CriticalPathNode $node,
        AgentTaskSchedulerService $scheduler,
        CriticalPathNotificationService $notificationService,
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

            // Notify assignee and creator
            $notificationService->notifyParticipants($issue, []);
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
            ? "\n\nОписание: {$issue->description}"
            : '';

        $dueDate = $issue->due_date ? $issue->due_date->format('d.m.Y') : 'не задан';
        $assignee = $issue->assignee?->name ?? 'не назначен';
        $duration = round($node->duration_days, 1);
        $earlyStart = round($node->early_start ?? 0, 1);

        return <<<PROMPT
Ты — менеджер проекта. Перед тобой задача, которая находится на критическом пути проекта.

Задача: {$issue->name} (ID: #{$issue->id}){$description}

Параметры критического пути:
- Ожидаемая длительность: {$duration} рабочих дней
- Начало выполнения: через {$earlyStart} дней от сегодня
- Дедлайн: {$dueDate}
- Исполнитель: {$assignee}

Твои действия:
1. Если задача крупная (длительность > 3 дней) — декомпозируй её: создай подзадачи через create_entity (тип "issue") со ссылкой на родительскую задачу в описании
2. Наполни задачу: добавь acceptance criteria, уточни контекст и ожидаемый результат через update_entity
3. Оставь комментарий к задаче с вариантами решения и рекомендацией через create_entity (тип "issue_comment")

Помни: задача на критическом пути — любая задержка откладывает весь проект.
PROMPT;
    }
}
