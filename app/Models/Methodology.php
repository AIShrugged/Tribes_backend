<?php

namespace App\Models;

use App\Exceptions\AppException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Methodology extends Model
{
    protected $guarded = [];

    public function scopeOwned(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $query) use ($userId) {
            $query->where('user_id', $userId)
                ->orWhere('is_default', true);
        });
    }

    public function scopeVisibleFor(Builder $query, User $user, Organization $organization): Builder
    {
        if ($user->isOrganizationMember($organization)) {
            return $query->where(function (Builder $q) use ($organization) {
                $q->where('organization_id', $organization->id)
                    ->orWhere('is_default', true);
            });
        }

        return $query->where(function (Builder $q) use ($user, $organization) {
            $q->where('is_default', true)
                ->orWhere(function (Builder $q) use ($user, $organization) {
                    $q->where('organization_id', $organization->id)
                        ->whereHas('teams', fn (Builder $q) => $q->whereHas(
                            'users',
                            fn (Builder $q) => $q->where('users.id', $user->id)
                        ));
                });
        });
    }

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }

    public function isDefault(): bool
    {
        return $this->is_default;
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function getDefault(): self
    {
        return self::query()->where('is_default', true)->firstOrFail();
    }

    /**
     * Sync teams for this methodology.
     * Assigns specified teams, resets previously assigned teams not in the list to default.
     */
    public function syncTeams(array $teamIds): void
    {
        $invalidCount = Team::whereIn('id', $teamIds)
            ->where('organization_id', '!=', $this->organization_id)
            ->count();

        if ($invalidCount > 0) {
            throw new AppException('Some teams do not belong to this organization.', 'METHODOLOGY_ORG_MISMATCH');
        }

        // Reset teams that were assigned to this methodology but are not in the new list
        $this->teams()->whereNotIn('id', $teamIds)
            ->update(['methodology_id' => self::getDefault()->id]);

        // Assign the new teams
        if (!empty($teamIds)) {
            Team::whereIn('id', $teamIds)
                ->update(['methodology_id' => $this->id]);
        }
    }
}
