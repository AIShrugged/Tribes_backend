<?php

namespace App\Domain\Errors;

class InviteCancelledError extends BaseError
{
    protected static string $code = 'INVITE_CANCELLED';
    protected static string $message = 'Invitation has been cancelled.';
}