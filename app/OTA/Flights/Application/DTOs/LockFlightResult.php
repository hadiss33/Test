<?php

namespace App\Services\OTA\Flights\Application\DTOs;

final readonly class LockFlightResult
{
    public function __construct(
        public bool         $status,
        public string|false $lockId,        // LockId که Sepehr برمی‌گردونه
        public array        $rawResult,
        public string|false $errorCode    = false,
        public string|false $errorMessage = false,
    ) {}
}