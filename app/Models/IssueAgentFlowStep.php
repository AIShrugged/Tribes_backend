<?php

namespace App\Models;

use App\Enums\IssueAgentFlowStepKind;
use App\Enums\IssueAgentFlowStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueAgentFlowStep extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'definition' => 'array',
            'input_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => IssueAgentFlowStepStatus::class,
            'kind' => IssueAgentFlowStepKind::class,
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(IssueAgentFlow::class, 'issue_agent_flow_id');
    }

    public function agentTask(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class);
    }

    public function dependsOnStep(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_step_id');
    }
}
