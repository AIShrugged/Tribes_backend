<?php

namespace App\Domain\Errors;

class InvalidCurrentPasswordError extends BaseError
{
    protected static string $code    = 'INVALID_CURRENT_PASSWORD';
    protected static string $message = 'The current password is incorrect.';
}
