<?php

namespace App\Domain\Errors;

class InviteNotFoundError extends BaseError
{
    protected static string $code = 'INVITE_NOT_FOUND';
    protected static string $message = 'Invitation not found.';
}
