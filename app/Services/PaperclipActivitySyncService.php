<?php

namespace App\Services;

use App\Models\AgentActivityLog;
use App\Models\AgentTaskRun;
use Illuminate\Support\Facades\Log;

class PaperclipActivitySyncService
{
    public function __construct(
        private readonly PaperclipApiClient $client,
    ) {}

    /**
     * Fetch all activity for the given Paperclip issue and save it to AgentActivityLog.
     */
    public function syncForIssue(AgentTaskRun $run): void
    {
        $issueId = $run->paperclip_issue_id;
        $user    = $run->task?->user;

        if (! $issueId || ! $user) {
            Log::warning('PaperclipActivitySync: missing issueId or user', [
                'agent_task_run_id' => $run->id,
            ]);

            return;
        }

        $events = $this->client->getActivity([
            'entityType' => 'issue',
            'entityId'   => $issueId,
        ]);

        $count = 0;

        foreach ($events as $event) {
            $action    = $event['action'] ?? 'unknown';
            $toolName  = 'paperclip_' . $action;
            $occurredAt = isset($event['createdAt'])
                ? \Carbon\Carbon::parse($event['createdAt'])
                : now();

            AgentActivityLog::create([
                'user_id'           => $user->id,
                'agent_task_run_id' => $run->id,
                'tool_name'         => $toolName,
                'description'       => AgentActivityLog::descriptionFor($toolName, $event),
                'success'           => true,
                'tool_args'         => [
                    'actor'      => $event['actor'] ?? null,
                    'entityType' => $event['entityType'] ?? null,
                    'entityId'   => $event['entityId'] ?? null,
                ],
                'tool_result'       => $event,
                'created_at'        => $occurredAt,
            ]);

            $count++;
        }

        Log::info('PaperclipActivitySync: saved events', [
            'agent_task_run_id'  => $run->id,
            'paperclip_issue_id' => $issueId,
            'count'              => $count,
        ]);
    }
}
