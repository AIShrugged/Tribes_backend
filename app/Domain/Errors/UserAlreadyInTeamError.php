<?php

namespace App\Domain\Errors;

class UserAlreadyInTeamError extends BaseError
{
    protected static string $code = 'USER_ALREADY_IN_TEAM';
    protected static string $message = 'User is already a member of this team.';
}