<?php

namespace App\Models;

use App\Exceptions\AppException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Organization extends Model
{
    protected $guarded = [];

    public const TEMPLATES = ['IT'];

    protected function casts(): array
    {
        return [
            'onboarded_at' => 'datetime',
            'team_map'     => 'array',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function methodologies(): HasMany
    {
        return $this->hasMany(Methodology::class);
    }

    public function issueTypes(): HasMany
    {
        return $this->hasMany(OrganizationIssueType::class)
            ->with('agentProfile')
            ->orderBy('base_type')
            ->orderBy('key');
    }

    public function resolvedIssueTypes(): Collection
    {
        $issueTypes = OrganizationIssueType::query()
            ->where(function ($query): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $this->id);
            })
            ->where('is_active', true)
            ->with('agentProfile')
            ->orderByRaw('CASE WHEN organization_id = ? THEN 0 ELSE 1 END', [$this->id])
            ->orderBy('base_type')
            ->orderBy('key')
            ->get();

        return $issueTypes
            ->groupBy('key')
            ->map(function (Collection $group): OrganizationIssueType {
                return $group->firstWhere('organization_id', $this->id)
                    ?? $group->first();
            })
            ->values();
    }

    public function links(): HasMany
    {
        return $this->hasMany(OrganizationLink::class);
    }

    public function llmPrompts(): HasMany
    {
        return $this->hasMany(LlmPrompt::class);
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function deleteCompletely(): void
    {
        try {
            DB::beginTransaction();

            $teams = $this->teams;

            foreach ($teams as $team) {
                $team->deleteCompletely();
            }

            $this->users()->detach();

            $this->delete();

            DB::commit();
        } catch (\Exception $exception) {
            DB::rollBack();

            throw new AppException('Organization deletion failed. Contact support.', 'ORGANIZATION_DELETE_FAILED');
        }
    }
}
