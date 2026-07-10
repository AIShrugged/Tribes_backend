<?php

namespace App\Models;

use App\Services\Sources\Auth\SourceAuthDriver;
use App\Services\Sources\Auth\SourceAuthFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Source extends Model
{
    use SoftDeletes;
    protected $guarded = [];

    public function calendarEvents(): BelongsToMany
    {
        return $this->belongsToMany(CalendarEvent::class, 'calendar_event_source')
            ->withPivot('external_id', 'required_bot', 'organization_id')
            ->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
