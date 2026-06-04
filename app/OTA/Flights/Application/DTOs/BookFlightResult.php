<?php

namespace App\Services\OTA\Flights\Application\DTOs;

use App\Services\OTA\Flights\Domain\Entities\BookingResult;

final readonly class BookFlightResult
{
    public function __construct(
        public bool                 $status,
        public BookingResult|false  $booking,
        public array                $rawResult,
        public string|false         $errorCode = false,
        public string|false         $errorMessage = false,
    ) {}
}