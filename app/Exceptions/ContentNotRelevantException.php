<?php

namespace App\Exceptions;

class ContentNotRelevantException extends AppException
{
    public function __construct(string $message = 'The uploaded content does not appear to be related to your organization\'s work')
    {
        parent::__construct($message, 'CONTENT_NOT_RELEVANT', 422);
    }
}
