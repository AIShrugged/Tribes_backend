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
        return $this->belongsToMany(Profile::class)->withPivot('response_status');
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

    public function meetingTaskReview(): HasOne
    {
        return $this->hasOne(MeetingTaskReview::class);
    }

    public function issues(): MorphMany
    {
        return $this->morphMany(Issue::class, 'sourceable');
    }

    public function tasks(): MorphMany
    {
        return $this->issues();
    }

    /**
     * Issues created from OR discussed at this meeting (includes updated via comments).
     */
    public function issuesForMeeting(): \Illuminate\Database\Eloquent\Collection
    {
        return Issue::withoutTrashed()->forMeeting($this->id)->get();
    }

    public function agendas(): HasMany
    {
        return $this->hasMany(MeetingAgenda::class);
    }

    /**
     * Latest completed general (team-wide) agenda, with all filter constraints baked in.
     * Use for eager loading: `->with('generalAgenda')` is constant query count regardless of event count.
     */
    public function generalAgenda(): HasOne
    {
        return $this->hasOne(MeetingAgenda::class)
            ->whereNull('user_id')
            ->where('type', 'general')
            ->where('status', \App\Enums\AgendaStatus::DONE->value)
            ->latestOfMany();
    }

    public function scopeOwned(Builder $query, int $userId): Builder
    {
        return $query->whereHas('sources', fn (Builder $q) => $q->where('user_id', $userId));
    }

    /**
     * Stable identifier of the meeting series this event belongs to.
     * Prefers URL (survives renames); falls back to title when URL is absent.
     */
    public function seriesKey(): string
    {
        return $this->url
            ? 'url:' . $this->url
            : 'title:' . ($this->title ?? '');
    }

    public function scopeInSameSeriesAs(Builder $query, CalendarEvent $event): Builder
    {
        if ($event->url) {
            return $query->where('url', $event->url);
        }
        return $query->where('title', $event->title)->whereNull('url');
    }

    public function isRequiredBot(): bool
    {
        $query = DB::table('calendar_event_source')
            ->where('calendar_event_id', $this->id)
            ->where('required_bot', true);

        if ($this->creator_user_id) {
            $query
                ->join('sources', 'sources.id', '=', 'calendar_event_source.source_id')
                ->where('sources.user_id', $this->creator_user_id)
                ->whereNull('sources.deleted_at');
        }

        return $query->exists();
    }

    /**
     * Get the Recall external_id for API calls.
     *
     * Bot scheduling must use the host's Recall calendar-event id, which is
     * stored on the host source pivot row.
     */
    public function getRecallExternalId(): ?string
    {
        if (!$this->creator_user_id) {
            return $this->external_id;
        }

        return DB::table('calendar_event_source')
            ->join('sources', 'sources.id', '=', 'calendar_event_source.source_id')
            ->where('calendar_event_source.calendar_event_id', $this->id)
            ->where('sources.user_id', $this->creator_user_id)
            ->whereNull('sources.deleted_at')
            ->whereNotNull('calendar_event_source.external_id')
            ->orderBy('calendar_event_source.id')
            ->value('calendar_event_source.external_id') ?: $this->external_id;
    }
}
