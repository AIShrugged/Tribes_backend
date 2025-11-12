<?php

namespace App\Services\Sources\Auth\Drivers;

use App\Domain\Errors\NoSourceAuthError;
use App\Exceptions\AppException;
use App\Models\Source;
use App\Models\SourceOauth;
use App\Services\Sources\Auth\SourceAuthDriver;

class OAuthSourceAuthDriver implements SourceAuthDriver
{
    public function __construct(
        protected Source $source
    ) {
    }

    public function apply(array $options): array
    {
        $oauth = SourceOauth::firstWhere(['source_id' => $this->source->id]);

        if (!$oauth) {
            throw AppException::fromErrorClass(NoSourceAuthError::class);
        }

        $options['json']['oauth_client_id'] = config('services.google.client_id');
        $options['json']['oauth_client_secret'] = config('services.google.client_secret');
        $options['json']['oauth_refresh_token'] = $oauth->refresh_token;

        return $options;
    }
}
