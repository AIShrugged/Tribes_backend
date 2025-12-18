<?php

namespace App\Models;

use App\Services\Sources\Auth\SourceAuthDriver;
use App\Services\Sources\Auth\SourceAuthFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $guarded = [];

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function makeAuthDriver(): SourceAuthDriver
    {
        return SourceAuthFactory::make($this);
    }

    public function applyAuth(array $options): array
    {
        return $this->makeAuthDriver()->apply($options);
    }

    public function scopeOwned(Builder $builder, int $userId): Builder
    {
        return $builder->where('user_id', $userId);
    }

    public function disconnect(): void
    {
        $this->is_connected = false;
        $this->save();
    }
}
