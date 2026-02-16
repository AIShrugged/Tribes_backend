<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $guarded = [];

    /** In-request cache: name => id */
    private static array $idCache = [];

    /**
     * Return the channel ID for a given name.
     * Result is memoized for the lifetime of the request / job.
     */
    public static function idFor(string $name): ?int
    {
        if (!array_key_exists($name, static::$idCache)) {
            $id = static::where('name', $name)->value('id');
            static::$idCache[$name] = $id !== null ? (int) $id : null;
        }

        return static::$idCache[$name];
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }
}
