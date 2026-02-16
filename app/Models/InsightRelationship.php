<?php

namespace App\Models;

use App\Enums\InsightRelationshipType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsightRelationship extends Model
{
    protected $guarded = [];

    protected $casts = [
        'dynamics'            => 'array',
        'relationship_type'   => InsightRelationshipType::class,
        'last_interaction_at' => 'datetime',
    ];

    public function profileA(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id_a');
    }

    public function profileB(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id_b');
    }

    public static function findPair(int $profileIdA, int $profileIdB): ?self
    {
        [$a, $b] = self::sortIds($profileIdA, $profileIdB);

        return self::where('profile_id_a', $a)->where('profile_id_b', $b)->first();
    }

    public static function sortIds(int $a, int $b): array
    {
        return $a <= $b ? [$a, $b] : [$b, $a];
    }
}
