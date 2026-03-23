<?php

namespace App\Listeners;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Events\IssuesExtracted;
use App\Models\AgentTask;
use App\Models\Issue;
use App\Services\AgentTaskSchedulerService;
use Illuminate\Support\Facades\Log;

class DispatchAgentTasksForIssues
{
    private const ALLOWED_TOOLS = [
        'github_get_repository',
        'github_get_branch',
        'github_get_tree',
        'github_get_file_contents',
        'github_download_archive',
        'github_create_branch',
        'github_create_or_update_file',
        'github_create_pull_request',
        'github_get_pull_request_comments',
        'update_task_status',
        'create_issue',
        'create_followup_agent_task',
        'search_agent_memories',
    ];

    public function __construct(
        private readonly AgentTaskSchedulerService $scheduler,
    ) {}

    public function handle(IssuesExtracted $event): void
    {
        foreach ($event->issues as $issue) {
            $this->createAndDispatch($issue, $event);
        }
    }

    private function createAndDispatch(Issue $issue, IssuesExtracted $event): void
    {
        $prompt = $this->buildPrompt($issue);

        $agentTask = AgentTask::create([
            'user_id' => $event->user->id,
            'organization_id' => $event->team->organization_id,
            'team_id' => $event->team->id,
            'name' => "Issue #{$issue->id}: {$issue->name}",
            'prompt' => $prompt,
            'agent_task_type' => 'background',
            'schedule_type' => AgentScheduleType::ONE_OFF->value,
            'execution_mode' => AgentTaskExecutionMode::ISOLATED->value,
            'enabled' => true,
            'max_attempts' => 3,
            'next_run_at' => now(),
            'allowed_tools' => self::ALLOWED_TOOLS,
            'input_payload' => $this->buildInputPayload($issue),
            'metadata' => [
                'issue_id' => $issue->id,
                'auto_dispatched' => true,
                'max_iterations' => 25,
                'timeout_seconds' => 3600,
                'network_policy' => [
                    'restrict_hosts' => false,
                ],
            ],
        ]);

        $issue->update(['agent_task_id' => $agentTask->id]);

        $this->scheduler->dispatchTaskNow($agentTask);

        Log::info('Agent task dispatched for issue', [
            'issue_id' => $issue->id,
            'agent_task_id' => $agentTask->id,
        ]);
    }

    private function buildInputPayload(Issue $issue): array
    {
        return [
            'provider' => 'github',
            'owner' => config('github.default_owner', ''),
            'repo' => config('github.default_repo', ''),
            'issue_id' => $issue->id,
            'issue_name' => $issue->name,
            'issue_type' => $issue->type,
        ];
    }

    private function buildPrompt(Issue $issue): string
    {
        $description = $issue->description ? "\n\nОписание:\n{$issue->description}" : '';
        $owner = config('github.default_owner');
        $repo = config('github.default_repo');

        return <<<PROMPT
Ты получил задачу из экстракции встречи. Выполни её строго по шагам ниже.

Задача: {$issue->name}{$description}
Тип: {$issue->type}
Issue ID: {$issue->id}
Репозиторий: {$owner}/{$repo}

ВАЖНО: Все изменения файлов делай ТОЛЬКО через github_create_or_update_file (GitHub API), а НЕ через локальные файлы в sandbox. Для обновления существующего файла сначала получи его SHA через github_get_file_contents.

Обязательный порядок действий:
1. github_get_branch (owner: "{$owner}", repo: "{$repo}") — получить SHA ветки dev
2. github_create_branch — создать ветку "issue-{$issue->id}" от SHA dev
3. Для каждого файла который нужно изменить:
   a. github_get_file_contents — получить текущее содержимое и SHA файла
   b. github_create_or_update_file — закоммитить изменённый файл на ветку "issue-{$issue->id}"
4. github_create_pull_request — создать PR из "issue-{$issue->id}" в "dev"
5. update_task_status (task_id: {$issue->id}, status: "review") — поставить issue в review

Не пропускай шаги. Не завершай работу без создания PR.
Если задача не требует кода — поставь статус "done" через update_task_status.
PROMPT;
    }
}
