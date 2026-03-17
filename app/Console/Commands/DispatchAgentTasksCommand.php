<?php

namespace App\Console\Commands;

use App\Services\AgentTaskSchedulerService;
use Illuminate\Console\Command;

class DispatchAgentTasksCommand extends Command
{
    protected $signature = 'agent-tasks:dispatch {--limit=50 : Maximum number of due tasks to enqueue}';

    protected $description = 'Dispatch due agent tasks, including one-off and interval schedules';

    public function handle(AgentTaskSchedulerService $scheduler): int
    {
        $dispatched = $scheduler->dispatchDueTasks((int) $this->option('limit'));

        $this->info("Dispatched {$dispatched} agent task(s).");

        return self::SUCCESS;
    }
}
