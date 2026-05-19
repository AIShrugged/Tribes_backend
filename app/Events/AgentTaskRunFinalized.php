<?php

namespace App\Events;

use App\Enums\AgentTaskRunStatus;
use App\Models\AgentTaskRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentTaskRunFinalized
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AgentTaskRun $run,
        public readonly AgentTaskRunStatus $status,
    ) {}
}
