<?php

namespace App\Models;

use App\Enums\IssueAgentFlowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IssueAgentFlow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'agent_profile_id' => 'integer',
            'current_step_position' => 'integer',
            'plan_output' => 'string',
            'metadata' => 'array',
            'status' => IssueAgentFlowStatus::class,
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
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

    public function steps(): HasMany
    {
        return $this->hasMany(IssueAgentFlowStep::class)->orderBy('position');
    }

    public function planningStep(): HasOne
    {
        return $this->hasOne(IssueAgentFlowStep::class)->where('kind', 'planning');
    }

    public function currentStep(): HasOne
    {
        return $this->hasOne(IssueAgentFlowStep::class)->orderByDesc('position');
    }
}
