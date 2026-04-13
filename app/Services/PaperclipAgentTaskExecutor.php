<?php

namespace App\Services;

use App\Models\AgentTask;
use App\Models\AgentTaskRun;
use Illuminate\Support\Facades\Log;

class PaperclipAgentTaskExecutor
{
    public function __construct(
        private readonly PaperclipApiClient $client,
        private readonly AgentTaskContextBuilder $contextBuilder,
    ) {}

    /**
     * Create a Paperclip issue for the task and return immediately.
     * Completion is handled asynchronously via the webhook endpoint.
     */
    public function dispatch(AgentTask $task, AgentTaskRun $run): void
    {
        $context = $this->contextBuilder->build($task);

        $description = $this->buildDescription($context);

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

    private function buildDescription(array $context): string
    {
        $parts = [];

        if (! empty($context['system_prompt_extension'])) {
            $parts[] = $context['system_prompt_extension'];
        }

        if (! empty($context['user_prompt'])) {
            $parts[] = $context['user_prompt'];
        }

        return implode("\n\n", $parts);
    }
}
