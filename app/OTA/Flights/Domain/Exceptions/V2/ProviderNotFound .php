<?php

namespace App\Services\OTA\Flights\Domain\Exceptions\V2;

final class ProviderNotFound extends \Exception
{
    public static function forSupplier(string $supplier): self
    {
        return new self("API not found for supplier: {$supplier}");
    }

    public static function noActiveSupplier(): self
    {
        return new self('No active supplier selected for request');
    }
}