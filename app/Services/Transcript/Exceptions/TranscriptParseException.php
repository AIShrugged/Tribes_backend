<?php

namespace App\Services\Transcript\Exceptions;

use App\Services\Transcript\TranscriptFormat;

class TranscriptParseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?TranscriptFormat $format = null,
    ) {
        parent::__construct($message);
    }
}
