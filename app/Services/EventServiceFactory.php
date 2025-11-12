<?php

namespace App\Services;

use App\Enums\SourceType;
use App\Models\Source;

class EventServiceFactory
{
    public static function make(Source $source): SourceEventServiceInterface
    {
        return match ($source->type) {
            SourceType::GOOGLE_CALENDAR->value => new RecallEventService($source),
            default => new RecallEventService($source),
        };
    }
}
