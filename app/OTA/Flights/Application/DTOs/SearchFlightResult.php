<?php

namespace App\Services\OTA\Flights\Application\DTOs;

use App\Services\OTA\Flights\Domain\Entities\Flight;

final readonly class SearchFlightResult
{
    public function __construct(
        public bool    $status,
        public int     $time,
        public string  $currencyCode,
        /** @var Flight[] */
        public array   $flights,
        public array   $rawResult,
        public string|false $errorCode = false,
        public mixed        $errorMessage = false,
    ) {}
}