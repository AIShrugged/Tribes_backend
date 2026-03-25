<?php

namespace App\Models;

use App\Exceptions\AppException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Team extends Model
{
    protected $guarded = [];

    public function scopeVisibleFor(Builder $query, User $user, Organization $organization): Builder
    {
        if ($user->isOrganizationManager($organization)) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $q) => $q->where('users.id', $user->id));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function methodology(): BelongsTo
    {
        return $this->belongsTo(Methodology::class);
    }

    public function assignMethodology(int|Methodology $methodology): void
    {
        if (is_int($methodology)) {
            $methodology = Methodology::findOrFail($methodology);
        }

        if ($methodology->organization_id !== $this->organization_id) {
            throw new AppException('Cannot assign methodology to a team that does not belong to this organization', 'METHODOLOGY_ORG_MISMATCH');
        }

        $this->methodology_id = $methodology->id;
        $this->save();
    }

    public function teamUsers(): HasMany
    {
        return $this->hasMany(TeamUser::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }

    public function notificationSettings(): HasMany
    {
        return $this->hasMany(TeamNotificationSetting::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function getEmployeeCountAttribute(): int
    {
        return $this->users->count();
    }

    public function deleteCompletely(): void
    {
        try {
            DB::beginTransaction();

            $this->teamUsers()->delete();

            $this->delete();

            DB::commit();
        } catch (\Exception) {
            DB::rollBack();

            throw new AppException('Team deletion failed. Contact support.', 'TEAM_DELETE_FAILED');
        }
    }
}
