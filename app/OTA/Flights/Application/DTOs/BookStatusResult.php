<?php

namespace App\Services\OTA\Flights\Application\DTOs;

final readonly class BookStatusResult
{
    public function __construct(
        public bool         $status,
        public int|false    $statusId,      // StatusId از Sepehr: 1=issued, غیر1=failed
        public string|false $statusDesc,
        public string|false $localPnr,
        public string|false $failReason,
        public array        $rawResult,
        public string|false $errorCode    = false,
        public string|false $errorMessage = false,
    ) {}
}