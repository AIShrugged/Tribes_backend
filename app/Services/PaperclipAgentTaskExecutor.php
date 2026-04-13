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

    public function execute(AgentTask $task, AgentTaskRun $run): string
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

        return $this->pollUntilDone($task, $run, $issueId);
    }

    private function pollUntilDone(AgentTask $task, AgentTaskRun $run, string $issueId): string
    {
        $timeoutSeconds = $task->metadata['timeout_seconds']
            ?? config('paperclip.polling.max_seconds', 1800);

        $intervals = config('paperclip.polling.intervals', [1, 2, 5, 10, 10, 10]);
        $elapsed   = 0;
        $step      = 0;

        while (true) {
            $sleepSeconds = $intervals[min($step, count($intervals) - 1)];
            sleep($sleepSeconds);
            $elapsed += $sleepSeconds;
            $step++;

            $issue  = $this->client->getIssue($issueId);
            $status = $issue['status'] ?? null;

            Log::debug('Paperclip polling', [
                'agent_task_run_id'  => $run->id,
                'paperclip_issue_id' => $issueId,
                'status'             => $status,
                'elapsed'            => $elapsed,
            ]);

            if ($status === 'done') {
                return $this->extractOutput($issue, $issueId);
            }

            if ($status === 'cancelled') {
                throw new \RuntimeException("Paperclip issue {$issueId} was cancelled.");
            }

            if ($elapsed >= $timeoutSeconds) {
                throw new \RuntimeException(
                    "Paperclip polling timeout after {$elapsed}s for issue {$issueId}."
                );
            }
        }
    }

    private function extractOutput(array $issue, string $issueId): string
    {
        if (! empty($issue['planDocument'])) {
            return $issue['planDocument'];
        }

        $comments = $this->client->getIssueComments($issueId);

        if (! empty($comments)) {
            $last = end($comments);

            return $last['body'] ?? '';
        }

        return '';
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
