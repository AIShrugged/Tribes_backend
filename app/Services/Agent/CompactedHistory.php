<?php

namespace App\Services\Agent;

use Illuminate\Support\Collection;

class CompactedHistory
{
    public function __construct(
        public readonly Collection $messages,
        public readonly ?string $summary = null,
    ) {}
}
