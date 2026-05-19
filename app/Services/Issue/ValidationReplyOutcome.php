<?php

namespace App\Services\Issue;

class ValidationReplyOutcome
{
    public function __construct(
        public readonly int $issueId,
        public readonly bool $accepted,
        public readonly string $message,
    ) {}
}
