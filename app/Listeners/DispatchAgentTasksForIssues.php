<?php

namespace App\Listeners;

use App\Events\IssuesExtracted;

class DispatchAgentTasksForIssues
{
    public function handle(IssuesExtracted $event): void
    {
        // Intentionally no-op: issues are not auto-dispatched here anymore.
    }
}
