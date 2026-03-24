<?php

namespace App\Models;

use App\Enums\AgentScheduleType;
use App\Enums\AgentTaskExecutionMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentTask extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'interval_seconds' => 'integer',
            'max_attempts' => 'integer',
            'allowed_tools' => 'array',
            'allowed_outbound_hosts' => 'array',
            'input_payload' => 'array',
            'metadata' => 'array',
            'notification_telegram_chat_id' => 'integer',
            'notification_telegram_thread_id' => 'integer',
            'schedule_type' => AgentScheduleType::class,
            'execution_mode' => AgentTaskExecutionMode::class,
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'last_completed_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_agent_task_id');
    }

    public function originRun(): BelongsTo
    {
        return $this->belongsTo(AgentTaskRun::class, 'origin_agent_task_run_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentTaskRun::class);
    }

    public function followupTasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_agent_task_id');
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(AgentTaskRun::class)->latestOfMany();
    }

    public function isOneOff(): bool
    {
        return $this->schedule_type === AgentScheduleType::ONE_OFF;
    }

    public function isInterval(): bool
    {
        return $this->schedule_type === AgentScheduleType::INTERVAL;
    }

    public function isIsolated(): bool
    {
        return $this->effectiveExecutionMode() === AgentTaskExecutionMode::ISOLATED;
    }

    public function isInline(): bool
    {
        return $this->effectiveExecutionMode() === AgentTaskExecutionMode::INLINE;
    }

    public function effectiveExecutionMode(): AgentTaskExecutionMode
    {
        return $this->execution_mode
            ?? $this->profile?->execution_mode
            ?? AgentTaskExecutionMode::INLINE;
    }

    public function effectiveSandboxProfile(): ?string
    {
        return $this->sandbox_profile ?: $this->profile?->sandbox_profile;
    }

    public function effectiveAllowedTools(): array
    {
        if (is_array($this->allowed_tools)) {
            return $this->allowed_tools;
        }

        return is_array($this->profile?->allowed_tools) ? $this->profile->allowed_tools : [];
    }

    public function effectiveAllowedOutboundHosts(): array
    {
        if (is_array($this->allowed_outbound_hosts)) {
            return array_values(array_unique(array_filter($this->allowed_outbound_hosts, fn ($host) => is_string($host) && trim($host) !== '')));
        }

        if (is_array($this->profile?->allowed_outbound_hosts)) {
            return array_values(array_unique(array_filter($this->profile->allowed_outbound_hosts, fn ($host) => is_string($host) && trim($host) !== '')));
        }

        return [];
    }

    public function restrictsOutboundHosts(): bool
    {
        return ! ((bool) data_get($this->metadata, 'network_policy.restrict_hosts', true) === false);
    }

    public function usesPersistentSandboxWorkspace(): bool
    {
        return (bool) data_get($this->metadata, 'persistent_workspace.enabled', false);
    }

    public function persistentSandboxWorkspaceKey(): ?string
    {
        $explicit = trim((string) data_get($this->metadata, 'persistent_workspace.key', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $payload = $this->input_payload ?? [];
        if (! is_array($payload)) {
            return null;
        }

        $provider = trim((string) ($payload['provider'] ?? ''));
        $owner = trim((string) ($payload['owner'] ?? ''));
        $repo = trim((string) ($payload['repo'] ?? ''));

        if ($provider === '' || $owner === '' || $repo === '') {
            return null;
        }

        return strtolower($provider.'-'.$owner.'-'.$repo);
    }

    public function nextRunFrom(?CarbonInterface $from = null): ?CarbonInterface
    {
        if (! $this->isInterval()) {
            return null;
        }

        $intervalSeconds = max(1, (int) $this->interval_seconds);
        $base = $from ? $from->copy() : now();

        return $base->addSeconds($intervalSeconds);
    }
}
