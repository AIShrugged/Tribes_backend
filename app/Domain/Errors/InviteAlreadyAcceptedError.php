<?php

namespace App\Domain\Errors;

class InviteAlreadyAcceptedError extends BaseError
{
    protected static string $code = 'INVITE_ALREADY_ACCEPTED';
    protected static string $message = 'Invitation has already been accepted.';
}