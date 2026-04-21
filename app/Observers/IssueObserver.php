<?php

namespace App\Observers;

use App\Enums\AgentScheduleType;
use App\Enums\MeetingTaskStatus;
use App\Models\AgentTask;
use App\Models\DailyNudge;
use App\Models\Issue;
use App\Services\AgentTaskSchedulerService;
use Carbon\Carbon;
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

        // Invalidate nudge when task status changes — data is now stale
        if ($issue->assignee_id) {
            DailyNudge::query()
                ->where('user_id', $issue->assignee_id)
                ->where('date', Carbon::today()->format('Y-m-d'))
                ->delete();
        }

        if ($issue->status !== MeetingTaskStatus::REOPEN->value) {
            return;
        }

        $this->handleReopen($issue);
    }

    private function handleReopen(Issue $issue): void
    {
        $sourceTask = $issue->agentTask()->first();
        $prompt = $this->buildReopenPrompt($issue);

        $agentTask = AgentTask::create($this->buildReopenTaskData($issue, $sourceTask, $prompt));

        $issue->updateQuietly(['agent_task_id' => $agentTask->id]);

        $this->scheduler->dispatchTaskNow($agentTask);

        Log::info('Agent task dispatched for reopened issue', [
            'issue_id' => $issue->id,
            'agent_task_id' => $agentTask->id,
        ]);
    }

    private function buildReopenTaskData(Issue $issue, ?AgentTask $sourceTask, string $prompt): array
    {
        $metadata = is_array($sourceTask?->metadata) ? $sourceTask->metadata : [];

        return [
            'user_id' => $issue->user_id,
            'organization_id' => $issue->organization_id,
            'team_id' => $issue->team_id,
            'name' => "Reopen Issue #{$issue->id}: {$issue->name}",
            'prompt' => $prompt,
            'agent_profile_id' => $sourceTask?->agent_profile_id,
            'schedule_type' => AgentScheduleType::ONE_OFF->value,
            'execution_mode' => $sourceTask?->execution_mode?->value,
            'sandbox_profile' => $sourceTask?->sandbox_profile,
            'agent_task_type' => $sourceTask?->agent_task_type ?? 'background',
            'output_mode' => $sourceTask?->output_mode ?? 'plain',
            'enabled' => true,
            'max_attempts' => $sourceTask?->max_attempts ?? 3,
            'next_run_at' => now(),
            'allowed_tools' => $sourceTask?->allowed_tools ?? self::ALLOWED_TOOLS,
            'allowed_outbound_hosts' => $sourceTask?->allowed_outbound_hosts ?? [],
            'input_payload' => $this->buildInputPayload($issue),
            'metadata' => [
                ...$metadata,
                'issue_id' => $issue->id,
                'reopen' => true,
                'previous_agent_task_id' => $sourceTask?->id ?? $issue->agent_task_id,
            ],
        ];
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
