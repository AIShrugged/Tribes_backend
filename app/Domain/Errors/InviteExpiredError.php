<?php

namespace App\Domain\Errors;

class InviteExpiredError extends BaseError
{
    protected static string $code = 'INVITE_EXPIRED';
    protected static string $message = 'Invitation has expired.';
}