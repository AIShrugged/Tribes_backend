<?php

namespace App\Models;

use App\Enums\DecisionSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Decision extends Model
{
    protected $guarded = [];

    protected $casts = [
        'source_type' => DecisionSourceType::class,
    ];

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
            ->withPivot('created_at');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(DecisionFollowup::class);
    }
}
