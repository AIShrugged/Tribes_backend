<?php

namespace App\Services\Sources\Auth\Drivers;

use App\Services\Sources\Auth\SourceAuthDriver;

class NoneSourceAuthDriver implements SourceAuthDriver
{
    public function apply(array $options): array
    {
        return $options;
    }
}
