<?php

namespace App\Listeners;

use App\Events\IssuesExtracted;
use Illuminate\Support\Facades\Log;

class DispatchAgentTasksForIssues
{
    public function handle(IssuesExtracted $event): void
    {
        if ($event->issues->isEmpty()) {
            return;
        }

        Log::info('Auto-dispatch for extracted issues is disabled; issues remain pending manual dispatch.', [
            'count' => $event->issues->count(),
            'team_id' => $event->team->id,
            'user_id' => $event->user->id,
        ]);
    }
}
