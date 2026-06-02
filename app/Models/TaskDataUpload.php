<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TaskDataUpload extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issues_created' => 'integer',
            'issues_updated' => 'integer',
        ];
    }

    /**
     * Org + team + own-uploader visibility. Explicit where-builder, no relation
     * traversal. NOTE (D4): org-level matching means an org member NOT on the team
     * can see the row (filename + counts); the detail endpoint separately re-filters
     * issue NAMES through Issue::scopeVisibleTo.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $orgIds  = $user->organizations()->pluck('organizations.id');
        $teamIds = $user->teams()->pluck('teams.id');

        return $query->where(function (Builder $q) use ($orgIds, $teamIds, $user) {
            if ($teamIds->isNotEmpty()) {
                $q->orWhereIn('team_id', $teamIds);
            }
            if ($orgIds->isNotEmpty()) {
                $q->orWhereIn('organization_id', $orgIds);
            }
            $q->orWhere('user_id', $user->id); // ungated: always see your own uploads
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function issues(): MorphMany
    {
        return $this->morphMany(Issue::class, 'sourceable');
    }
}
