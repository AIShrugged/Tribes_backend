<?php

namespace App\Models;

use App\Enums\AgentTaskRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentTaskRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'metadata' => 'array',
            'status' => AgentTaskRunStatus::class,
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'run_token_expires_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'agent_task_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AgentActivityLog::class)->orderBy('created_at');
    }
}
