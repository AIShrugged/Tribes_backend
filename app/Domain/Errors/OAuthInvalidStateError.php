<?php

namespace App\Domain\Errors;

use App\Domain\Errors\BaseError;

class OAuthInvalidStateError extends BaseError
{
    protected static string $code = 'OAUTH_INVALID_STATE';
    protected static string $message = 'Session expired. Please refresh the page and try again.';
}
