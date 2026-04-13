<?php

namespace App\Services\Paperclip\Handlers;

use App\Enums\AgentTaskRunStatus;
use App\Models\AgentTaskRun;
use App\Services\Paperclip\PaperclipEventHandlerInterface;
use App\Services\Paperclip\PaperclipPayloadInterface;
use App\Services\Paperclip\Payloads\AgentRunPayload;
use Illuminate\Support\Facades\Log;

class AgentRunStartedHandler implements PaperclipEventHandlerInterface
{
    public function handle(AgentRunPayload|PaperclipPayloadInterface $payload): void
    {
        $run = AgentTaskRun::where('paperclip_issue_id', $payload->issueId)->first();

        if (! $run) {
            Log::warning('Paperclip webhook agent.run.started: run not found', [
                'paperclip_issue_id' => $payload->issueId,
            ]);

            return;
        }

        if ($run->status !== AgentTaskRunStatus::QUEUED) {
            return;
        }

        $run->update([
            'status'     => AgentTaskRunStatus::PROCESSING->value,
            'started_at' => $run->started_at ?? now(),
        ]);

        Log::info('Paperclip agent run started', [
            'agent_task_run_id'  => $run->id,
            'paperclip_issue_id' => $payload->issueId,
        ]);
    }
}
