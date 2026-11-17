<?php

namespace App\Exceptions;

use Exception;

class BusinessException extends Exception
{
    public function __construct(
        string $message,
        protected string $errorCode = 'BUSINESS_RULE_VIOLATION',
        protected mixed $details = null,
        protected int $statusCode = 409
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function details(): mixed
    {
        return $this->details;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
