<?php

namespace App\Models;

use App\Exceptions\AppException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Organization extends Model
{
    protected $guarded = [];

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
