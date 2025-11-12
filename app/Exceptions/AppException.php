<?php

namespace App\Exceptions;

use App\Domain\Errors\BaseError;

class AppException extends \RuntimeException
{
    private string $errorCode;
    private array $context;

    /**
     * @param class-string<BaseError> $errorClass
     */
    public function __construct(string $message = '', string $errorCode = '', int $status = 500, array $context = [])
    {
        parent::__construct($message, $status);

        $this->errorCode = $errorCode;
        $this->context = $context;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param class-string<BaseError> $errorClass
     */
    public static function fromErrorClass(string $errorClass, int $status = 500, array $context = []): AppException
    {
        return new self($errorClass::getMessage(), $errorClass::getCode(), $status, $context);
    }
}
