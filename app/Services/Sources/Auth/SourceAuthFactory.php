<?php

namespace App\Services\Sources\Auth;

use App\Enums\SourceAuthType;
use App\Models\Source;
use App\Services\Sources\Auth\Drivers\NoneSourceAuthDriver;
use App\Services\Sources\Auth\Drivers\OAuthSourceAuthDriver;

class SourceAuthFactory
{
    public static function make(Source $source): SourceAuthDriver
    {
        return match ($source->auth_type) {
            SourceAuthType::OAUTH2->value => new OAuthSourceAuthDriver($source),
            default => new NoneSourceAuthDriver()
        };
    }
}
