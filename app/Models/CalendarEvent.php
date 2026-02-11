<?php

namespace App\Models;

use App\Services\Recall\RecallBotService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    public function bot(): HasOne
    {
        return $this->hasOne(Bot::class);
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

    public function meetingTasks(): HasMany
    {
        return $this->hasMany(MeetingTask::class);
    }

    public function scopeOwned(Builder $query, int $userId): Builder
    {
        return $query->whereHas('source', function (Builder $query) use ($userId) {
            $query->where('user_id', $userId);
        });
    }

    public function scheduleBot(): void
    {
        if (!$this->required_bot && !$this->bot) {
            return;
        }

        DB::transaction(function () {
            if ($this->shouldRemoveBot()) {
                app(RecallBotService::class)->removeBot($this);
                $this->bot()->delete();

                return;
            }

            $botDTO = app(RecallBotService::class)->schedule($this);

            $this->bot()->updateOrCreate(
                ['external_id' => $botDTO->externalId],
                ['deduplication_key' => $botDTO->deduplicationKey]
            );
        });
    }

    public function shouldRemoveBot(): bool
    {
        return !$this->required_bot && $this->bot;
    }

    public function requiredBot(bool $require): void
    {
        $this->required_bot = $require;
        $this->save();
    }
}
