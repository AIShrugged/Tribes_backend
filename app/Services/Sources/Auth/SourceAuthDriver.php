<?php

namespace App\Services\Sources\Auth;

interface SourceAuthDriver
{
    public function apply(array $options): array;
}
