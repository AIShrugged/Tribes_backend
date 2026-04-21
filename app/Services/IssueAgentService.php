<?php

namespace App\Services;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use App\Models\AgentProfile;
use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Issue;
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
        if ($issue->isDevelopment() && ! $issue->wasLastExecutedByPaperclip()) {
            return $this->flowService->start($issue, $user, $agentProfileId);
        }

        if ($issue->wasLastExecutedByPaperclip()) {
            return $this->dispatchPaperclipReopen($issue, $user);
        }

        if ($issue->agent_task_id) {
            $activeRun = AgentTaskRun::query()
                ->where('agent_task_id', $issue->agent_task_id)
                ->whereIn('status', ['queued', 'processing'])
                ->exists();

            if ($activeRun) {
                throw new \App\Exceptions\AppException(
                    'Agent task is already running for this issue.',
                    'ISSUE_AGENT_TASK_ALREADY_RUNNING',
                    409,
                );
            }
        }

        $task = $this->createTask($issue, $user, $agentProfileId);

        $issue->update([
            'agent_task_id' => $task->id,
            'status' => 'in_progress',
            'last_agent_execution_mode' => $task->effectiveExecutionMode()->value,
        ]);

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

    private function dispatchPaperclipReopen(Issue $issue, User $user): AgentTaskRun
    {
        $task = AgentTask::create([
            'user_id' => $issue->paperclip_user_id ?? $user->id,
            'organization_id' => $issue->organization_id,
            'team_id' => $issue->team_id,
            'name' => "Issue #{$issue->id}: {$issue->name}",
            'prompt' => $this->buildPaperclipReopenPrompt($issue),
            'schedule_type' => AgentScheduleType::ONE_OFF->value,
            'execution_mode' => AgentTaskExecutionMode::PAPERCLIP->value,
            'agent_task_type' => 'background',
            'enabled' => true,
            'next_run_at' => now(),
            'max_attempts' => 3,
            'input_payload' => $this->buildInputPayload($issue, null),
            'metadata' => $this->buildTaskMetadata(null),
        ]);

        $issue->update([
            'agent_task_id' => $task->id,
            'status' => 'in_progress',
            'last_agent_execution_mode' => AgentTaskExecutionMode::PAPERCLIP->value,
            'paperclip_user_id' => $issue->paperclip_user_id ?? $user->id,
        ]);

        $run = $this->scheduler->dispatchTaskNow($task);

        if (! $run) {
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
        $resolvedAgentProfileId = $issue->effectiveAgentProfileId($agentProfileId);
        $profile = $resolvedAgentProfileId ? AgentProfile::query()->find($resolvedAgentProfileId) : null;

        return AgentTask::create([
            'user_id'          => $user->id,
            'organization_id'  => $issue->organization_id,
            'team_id'          => $issue->team_id,
            'name'             => "Issue #{$issue->id}: {$issue->name}",
            'prompt'           => $prompt,
            'agent_profile_id' => $resolvedAgentProfileId,
            'schedule_type'    => AgentScheduleType::ONE_OFF->value,
            'execution_mode'   => $resolvedAgentProfileId ? null : AgentTaskExecutionMode::INLINE->value,
            'agent_task_type'  => 'background',
            'enabled'          => true,
            'next_run_at'      => now(),
            'input_payload'    => $this->buildInputPayload($issue, $profile),
            'metadata'         => $this->buildTaskMetadata($profile),
        ]);
    }

    private function buildInputPayload(Issue $issue, ?AgentProfile $profile): array
    {
        $payload = [
            'issue_id' => $issue->id,
            'issue_type' => $issue->type,
            'organization_id' => $issue->organization_id,
            'team_id' => $issue->team_id,
        ];

        if ($profile && is_array($profile->metadata)) {
            $payload['profile_metadata'] = $profile->metadata;
        }

        if ($issue->pr_number) {
            $payload['pr_number'] = $issue->pr_number;
        }

        if ($issue->pr_url) {
            $payload['pr_url'] = $issue->pr_url;
        }

        if ($issue->pr_repository) {
            $payload['pr_repository'] = $issue->pr_repository;
        }

        // Pass repository config from issue type metadata so agents can auto-detect the right repo
        $issueTypeMetadata = $issue->issueType?->metadata;
        if (is_string($issueTypeMetadata)) {
            $issueTypeMetadata = json_decode($issueTypeMetadata, true);
        }
        if (is_array($issueTypeMetadata)) {
            $payload['issue_type_metadata'] = $issueTypeMetadata;
        }

        return $payload;
    }

    private function buildTaskMetadata(?AgentProfile $profile): array
    {
        return [
            'profile_metadata' => is_array($profile?->metadata) ? $profile->metadata : [],
        ];
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

    private function buildPaperclipReopenPrompt(Issue $issue): string
    {
        $description = $issue->description ? "\nОписание задачи: {$issue->description}" : '';
        $prContext = '';
        $callbackBlock = $this->buildPaperclipCallbackBlock();

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
            ."\n\n{$callbackBlock}"
            ."\n\nОбщие инструкции:"
            ."\n1. Проанализируй причину reopen"
            ."\n2. Если есть PR — прочитай комментарии и исправь код"
            ."\n3. Если PR нет — выполни необходимые действия"
            ."\n4. После завершения поставь статус \"review\" через update_task_status";
    }

    private function buildPaperclipCallbackBlock(): string
    {
        return <<<PROMPT
## Paperclip completion callback

This callback is mandatory.
It updates both the Tribes agent run and the linked Tribes issue.

After you reach a final state, send a POST request to:
{app_url}/api/v1/internal/paperclip/issues/{issue_id}/status

Use this header:
X-Paperclip-Run-Token: {run_token}

Body:
```json
{
  "status": "done | blocked | failed",
  "last_comment": "Your latest meaningful comment",
  "artifacts": [
    {
      "filename": "result.md",
      "content_base64": "base64 file content or plain text content",
      "mime_type": "text/markdown"
    }
  ]
}
```

Rules:
- Send exactly one final callback when the issue is done, blocked, or failed.
- Use `done` for successful completion.
- Use `blocked` when you cannot proceed because of a missing input, permission, or decision.
- Use `failed` when the task cannot be completed.
- Always include the latest meaningful comment in `last_comment`.
- Always include `artifacts`, even if it is an empty array.
- Include any final artifacts or files in `artifacts` when available.
- If an artifact contains file content, include `filename` and either `content_base64` or `content`.
- For binary files, prefer `content_base64`.
- For text files, you may send `content` or `content_base64`, but `content_base64` is preferred for consistency.
- This callback must include the latest comment even if the issue status is already clear from context.
- Replace `{issue_id}` and `{run_token}` with the current issue id and run token.
PROMPT;
    }
}
