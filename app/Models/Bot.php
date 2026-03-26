<?php

namespace App\Models;

use App\Enums\BotEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BotEvent::class);
    }

    public function logEvent(BotEventType $type): BotEvent
    {
        return $this->events()->create([
            'type'        => $type,
            'occurred_at' => now(),
        ]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
        $this->logEvent(BotEventType::REMOVED);
    }

    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }
}
