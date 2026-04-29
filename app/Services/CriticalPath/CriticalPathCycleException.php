<?php

namespace App\Services\CriticalPath;

class CriticalPathCycleException extends \RuntimeException
{
    public function __construct(string $message = 'Cycle detected in critical path graph')
    {
        parent::__construct($message);
    }
}
