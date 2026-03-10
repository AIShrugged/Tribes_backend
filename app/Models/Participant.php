<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Participant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'profile_confidence' => 'integer',
        ];
    }

    public function profile(): ?BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
