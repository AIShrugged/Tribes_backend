<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

class CalendarEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'calendar_event_source')
            ->withPivot('external_id', 'required_bot')
            ->withTimestamps();
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function transcriptEntries(): HasMany
    {
        return $this->hasMany(TranscriptEntry::class);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }

    public function meetingSummary(): HasOne
    {
        return $this->hasOne(MeetingSummary::class);
    }

    public function meetingReview(): HasOne
    {
        return $this->hasOne(MeetingReview::class);
    }

    public function issues(): MorphMany
    {
        return $this->morphMany(Issue::class, 'sourceable');
    }

    public function tasks(): MorphMany
    {
        return $this->issues();
    }

    public function agendas(): HasMany
    {
        return $this->hasMany(MeetingAgenda::class);
    }

    public function scopeOwned(Builder $query, int $userId): Builder
    {
        return $query->whereHas('sources', fn (Builder $q) => $q->where('user_id', $userId));
    }

    public function isRequiredBot(): bool
    {
        return DB::table('calendar_event_source')
            ->where('calendar_event_id', $this->id)
            ->where('required_bot', true)
            ->exists();
    }

    /**
     * Get any Recall external_id from the pivot (for API calls to Recall).
     */
    public function getRecallExternalId(): ?string
    {
        return DB::table('calendar_event_source')
            ->where('calendar_event_id', $this->id)
            ->whereNotNull('external_id')
            ->value('external_id');
    }
}
