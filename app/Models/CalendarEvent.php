<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CalendarEvent extends Model
{
    protected $guarded = [];

    public function host(): BelongsTo
    {
        return $this->source->user();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
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
        return $query->whereHas('source', function (Builder $query) use ($userId) {
            $query->where('user_id', $userId);
        });
    }

    public function requiredBot(bool $require): void
    {
        $this->required_bot = $require;
        $this->save();
    }
}
