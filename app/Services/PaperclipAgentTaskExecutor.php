<?php

namespace App\Services;

use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use App\Models\Profile;
use Illuminate\Support\Facades\Log;

class PaperclipAgentTaskExecutor
{
    public function __construct(
        private readonly PaperclipApiClient $client,
        private readonly AgentTaskContextBuilder $contextBuilder,
        private readonly PaperclipCallbackTokenService $callbackTokenService,
    ) {}

    /**
     * Create a Paperclip issue for the task and return immediately.
     * Completion is handled asynchronously via the Paperclip callback endpoint.
     */
    public function dispatch(AgentTask $task, AgentTaskRun $run): void
    {
        $callbackToken = $this->callbackTokenService->issue($run);
        $context = $this->contextBuilder->build($task);

        $description = $this->buildDescription($task, $context, $callbackToken);

        $issue = $this->client->createIssue([
            'title'           => $task->name,
            'description'     => $description,
            'assigneeAgentId' => config('paperclip.agent_id'),
            'status'          => 'todo',
        ]);

        $issueId = $issue['id'];

        $run->update([
            'paperclip_issue_id' => $issueId,
            'metadata'           => array_merge($run->metadata ?? [], [
                'paperclip_issue_id' => $issueId,
            ]),
        ]);

        Log::info('Paperclip issue created', [
            'agent_task_id'      => $task->id,
            'agent_task_run_id'  => $run->id,
            'paperclip_issue_id' => $issueId,
        ]);
    }

    private function buildDescription(AgentTask $task, array $context, string $callbackToken): string
    {
        $parts = [];
        $appUrl = rtrim((string) config('app.url'), '/');

        // Date/time context (same as AgentService injects into user messages)
        $now = now()->timezone('Europe/Moscow');
        $parts[] = "[Current date: {$now->format('Y-m-d')}, time: {$now->format('H:i')} MSK]";

        // User identity context
        $user = $task->user()->first();
        if ($user) {
            $profileId = Profile::where('user_id', $user->id)->value('id');
            $userName  = $user->name ?? 'Unknown';
            if ($profileId) {
                $parts[] = "[Sender: {$userName} (user_id={$user->id}, profile_id={$profileId})]";
            }
        }

        // System-level instructions (profile prompt, metadata, memories)
        if (! empty($context['system_prompt_extension'])) {
            $parts[] = $context['system_prompt_extension'];
        }

        // Task lineage (parent task, followup depth, handoff context summary)
        $lineage = $context['task_lineage'] ?? [];
        if (! empty($lineage)) {
            $lineageParts = [];
            if (! empty($lineage['followup_depth'])) {
                $lineageParts[] = "followup_depth: {$lineage['followup_depth']}";
            }
            if (! empty($lineage['parent_task_id'])) {
                $lineageParts[] = "parent_task_id: {$lineage['parent_task_id']}";
            }
            if (! empty($lineage['inherited_context_summary'])) {
                $lineageParts[] = "context_summary: {$lineage['inherited_context_summary']}";
            }
            if ($lineageParts) {
                $parts[] = "## Task Lineage\n\n".implode("\n", $lineageParts);
            }
        }

        // Followup policy
        $policy = $context['followup_policy'] ?? [];
        if (! empty($policy)) {
            $parts[] = "## Followup Policy\n\n```json\n".json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n```";
        }

        $parts[] = <<<PROMPT
## Paperclip completion callback

This callback is mandatory.
It updates both the Tribes agent run and the linked Tribes issue.

After you reach a final state, send a POST request to:
{$appUrl}/api/v1/internal/paperclip/issues/{issue_id}/status

Use this header:
X-Paperclip-Run-Token: {$callbackToken}

Body:
```json
{
  "status": "done | blocked | failed",
  "last_comment": "Your latest meaningful comment",
  "artifacts": [
    {
      "filename": "result.md",
      "content": "plain text or base64",
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
- Include any final artifacts or files in `artifacts` when available.
- If an artifact contains file content, include `filename` and either `content_base64` or `content`.
- For binary files, prefer `content_base64`.
- This callback must include the latest comment even if the issue status is already clear from context.
- Replace `{issue_id}` with the current Paperclip issue id.
PROMPT;

        // The actual task prompt + payload
        if (! empty($context['user_prompt'])) {
            $parts[] = $context['user_prompt'];
        }

        return implode("\n\n", $parts);
    }
}
