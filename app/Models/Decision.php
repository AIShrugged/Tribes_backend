<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Decision extends Model
{
    protected $guarded = [];

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }

    public function summary(): BelongsTo
    {
        return $this->belongsTo(MeetingSummary::class, 'summary_id');
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function authorProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'author_profile_id');
    }

    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'decision_issue')
            ->withTimestamps(['created_at', null]);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(DecisionFollowup::class);
    }
}
