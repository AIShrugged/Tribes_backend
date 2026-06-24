<?php

namespace App\Services\Agent\Query;

use RuntimeException;

/**
 * Thrown when a structured query fails validation against the catalog (unknown
 * field, trap field, unknown relation, missing tenant scope, ...). Carries all
 * collected errors so the agent can be told everything that was wrong at once.
 */
class StructuredQueryException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }
}
