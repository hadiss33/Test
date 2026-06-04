<?php

namespace App\Services\OTA\Flights\Domain\Exceptions;

use RuntimeException;

class ProviderException extends RuntimeException
{
    public function __construct(
        string          $message,
        private string  $providerCode = '',
        int             $code = 0,
        ?\Throwable     $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getProviderCode(): string
    {
        return $this->providerCode;
    }
}