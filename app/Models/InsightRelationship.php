<?php

namespace App\Models;

use App\Enums\InsightRelationshipType;
use Illuminate\Database\Eloquent\Model;

class InsightRelationship extends Model
{
    protected $guarded = [];

    protected $casts = [
        'dynamics'          => 'array',
        'relationship_type' => InsightRelationshipType::class,
        'last_interaction_at' => 'datetime',
    ];

    public static function findPair(string $emailA, string $emailB): ?self
    {
        [$a, $b] = self::sortEmails($emailA, $emailB);

        return self::where('email_a', $a)->where('email_b', $b)->first();
    }

    public static function sortEmails(string $emailA, string $emailB): array
    {
        return strcmp($emailA, $emailB) <= 0
            ? [$emailA, $emailB]
            : [$emailB, $emailA];
    }
}
