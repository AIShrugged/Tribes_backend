<?php

namespace App\Domain\Errors;

use App\Domain\Errors\BaseError;

class NoSourceAuthError extends BaseError
{
    protected static string $code = 'SOURCE_NO_AUTH';
    protected static string $message = 'No corresponding authentication was found for the source.';
}
