<?php

namespace App\Domain\Errors;

use App\Domain\Errors\BaseError;

class SourceExistsError extends BaseError
{
    protected static string $code = 'SOURCE_ALREADY_EXISTS';
    protected static string $message = 'Source already exists.';
}
