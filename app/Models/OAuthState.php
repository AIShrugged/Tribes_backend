<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OAuthState extends Model
{
    protected $table = 'oauth_states';
    protected $guarded = [];

    protected const TTL_MINUTES = 10;

    public function isValid(): bool
    {
        return $this->updated_at->gt(now()->subMinutes(self::TTL_MINUTES));
    }
}
