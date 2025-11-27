<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Profile extends Model
{
    protected $fillable = ['email'];

    public function calendarEvents(): BelongsToMany
    {
        return $this->belongsToMany(CalendarEvent::class);
    }
}
