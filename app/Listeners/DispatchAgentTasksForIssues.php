<?php

namespace App\Listeners;

use App\Events\IssuesExtracted;
use App\Services\IssueAgentService;
use Illuminate\Support\Facades\Log;

class DispatchAgentTasksForIssues
{
    public function __construct(
        private readonly IssueAgentService $issueAgentService,
    ) {}

    public function handle(IssuesExtracted $event): void
    {
        foreach ($event->issues as $issue) {
            try {
                $this->issueAgentService->dispatch($issue, $event->user);
            } catch (\Throwable $e) {
                Log::error('Failed to auto-dispatch agent task for issue', [
                    'issue_id' => $issue->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
