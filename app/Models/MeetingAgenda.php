<?php

namespace App\Models;

use App\Enums\AgendaStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAgenda extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_json' => 'array',
            'sent_at' => 'datetime',
            'send_scheduled_at' => 'datetime',
            'status' => AgendaStatus::class,
        ];
    }

    public function calendarEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeGeneral(Builder $query): Builder
    {
        return $query->where('type', 'general');
    }

    public function scopePersonal(Builder $query): Builder
    {
        return $query->where('type', 'personal');
    }

    public function scopeUnsent(Builder $query): Builder
    {
        return $query->whereNull('sent_at')->where('status', AgendaStatus::DONE);
    }

    public function isGeneral(): bool
    {
        return $this->type === 'general';
    }

    public function isPersonal(): bool
    {
        return $this->type === 'personal';
    }
}
