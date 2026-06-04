<?php

namespace App\Services\OTA\Flights\Domain\Exceptions;

class CredentialNotFoundException extends ProviderException
{
    public static function forBranch(int|string $branch): self
    {
        return new self(
            message: "Api Not Found!",
            providerCode: '2007',
        );
    }

    public static function noSupplierSelected(): self
    {
        return new self(
            message: 'هیچ تامین کننده ای برای ارسال درخواست انتخاب نشده است.',
            providerCode: '2004',
        );
    }
}