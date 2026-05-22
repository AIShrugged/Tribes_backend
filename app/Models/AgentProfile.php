<?php

namespace App\Models;

use App\Enums\AgentTaskExecutionMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allowed_tools' => 'array',
            'allowed_outbound_hosts' => 'array',
            'config_schema' => 'array',
            'task_payload_schema' => 'array',
            'metadata' => 'array',
            'execution_mode' => AgentTaskExecutionMode::class,
            'version' => 'integer',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AgentTask::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(AgentMemory::class);
    }

    public function promptVersions(): HasMany
    {
        return $this->hasMany(AgentProfilePromptVersion::class);
    }
}
