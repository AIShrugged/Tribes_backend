<?php

namespace App\Domain\Errors;

class InviteAlreadyExistsError extends BaseError
{
    protected static string $code = 'INVITE_ALREADY_EXISTS';
    protected static string $message = 'A pending invitation for this email already exists.';
}