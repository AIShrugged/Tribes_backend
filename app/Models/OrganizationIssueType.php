<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationIssueType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'issue_type_id');
    }

    public function isDevelopment(): bool
    {
        return $this->base_type === 'development';
    }

    public function isOrganization(): bool
    {
        return $this->base_type === 'organization';
    }
}
