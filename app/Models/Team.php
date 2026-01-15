<?php

namespace App\Models;

use App\Exceptions\AppException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $guarded = [];

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

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function getEmployeeCountAttribute(): int
    {
        return $this->users->count();
    }
}
