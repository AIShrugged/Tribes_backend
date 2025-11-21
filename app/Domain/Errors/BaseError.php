<?php

namespace App\Domain\Errors;

abstract class BaseError
{
    protected static string $code = '';
    protected static string $message = '';

    public static function getCode(): string
    {
        return static::$code;
    }

    public static function getMessage(): string
    {
        return static::$message;
    }
}
