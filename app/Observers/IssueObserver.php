<?php

namespace App\Observers;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Enums\MeetingTaskStatus;
use App\Models\AgentTask;
use App\Models\Issue;
use App\Services\AgentTaskSchedulerService;
use Illuminate\Support\Facades\Log;

class IssueObserver
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
        'create_followup_agent_task',
        'search_agent_memories',
    ];

    public function __construct(
        private readonly AgentTaskSchedulerService $scheduler,
    ) {}

    public function updated(Issue $issue): void
    {
        if (! $issue->isDirty('status')) {
            return;
        }

        $oldStatus = $issue->getOriginal('status');
        $newStatus = $issue->status;

        // Universal status-change log: covers every transition, including 'reviewed'.
        Log::info('Issue status changed', [
            'issue_id'   => $issue->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_at' => now()->toIso8601String(),
        ]);

        if ($issue->status === MeetingTaskStatus::REOPEN->value) {
            $this->handleReopen($issue);
        }
    }

    private function handleReopen(Issue $issue): void
    {
        $prompt = $this->buildReopenPrompt($issue);

        $agentTask = AgentTask::create([
            'user_id' => $issue->user_id,
            'organization_id' => $issue->organization_id,
            'team_id' => $issue->team_id,
            'name' => "Reopen Issue #{$issue->id}: {$issue->name}",
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
                'reopen' => true,
                'previous_agent_task_id' => $issue->agent_task_id,
                'max_iterations' => 25,
                'timeout_seconds' => 3600,
                'network_policy' => [
                    'restrict_hosts' => false,
                ],
            ],
        ]);

        $issue->updateQuietly(['agent_task_id' => $agentTask->id]);

        $this->scheduler->dispatchTaskNow($agentTask);

        Log::info('Agent task dispatched for reopened issue', [
            'issue_id' => $issue->id,
            'agent_task_id' => $agentTask->id,
        ]);
    }

    private function buildInputPayload(Issue $issue): array
    {
        $payload = [
            'provider' => 'github',
            'owner' => config('github.default_owner', ''),
            'repo' => config('github.default_repo', ''),
            'issue_id' => $issue->id,
            'issue_name' => $issue->name,
            'issue_type' => $issue->type,
        ];

        if ($issue->pr_number && $issue->pr_repository) {
            $parts = explode('/', $issue->pr_repository, 2);
            $payload['owner'] = $parts[0] ?? '';
            $payload['repo'] = $parts[1] ?? '';
            $payload['pr_number'] = $issue->pr_number;
            $payload['pr_url'] = $issue->pr_url;
        }

        return $payload;
    }

    private function buildReopenPrompt(Issue $issue): string
    {
        $description = $issue->description ? "\nОписание задачи: {$issue->description}" : '';
        $prContext = '';

        if ($issue->pr_number && $issue->pr_repository) {
            $parts = explode('/', $issue->pr_repository, 2);
            $owner = $parts[0] ?? '';
            $repo = $parts[1] ?? '';

            $prContext = "\n\nPR контекст:"
                ."\n- Репозиторий: {$issue->pr_repository}"
                ."\n- PR номер: {$issue->pr_number}"
                ."\n- PR URL: {$issue->pr_url}"
                ."\n\nИнструкции по PR:"
                ."\n1. Используй github_get_pull_request_comments чтобы прочитать комментарии ревьюера (owner: \"{$owner}\", repo: \"{$repo}\", pull_number: {$issue->pr_number})"
                ."\n2. Проанализируй каждый комментарий и пойми что нужно исправить"
                ."\n3. Скачай репозиторий через github_download_archive в sandbox workspace"
                ."\n4. Внеси необходимые изменения через github_create_or_update_file"
                ."\n5. После исправлений обнови статус issue на \"review\" через update_task_status";
        }

        return "Задача была reopened после ревью. Нужно проанализировать обратную связь и внести исправления."
            ."\n\nЗадача: {$issue->name}{$description}"
            ."\nIssue ID: {$issue->id}{$prContext}"
            ."\n\nОбщие инструкции:"
            ."\n1. Проанализируй причину reopen"
            ."\n2. Если есть PR — прочитай комментарии и исправь код"
            ."\n3. Если PR нет — выполни необходимые действия"
            ."\n4. После завершения поставь статус \"review\" через update_task_status";
    }
}