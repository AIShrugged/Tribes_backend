<?php

namespace App\Services\Transcript\Exceptions;

class TooManyEntriesException extends \RuntimeException
{
    public function __construct(public readonly int $count, public readonly int $limit)
    {
        parent::__construct("Transcript contains {$count} entries; limit is {$limit}.");
    }
}
