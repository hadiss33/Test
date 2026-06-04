<?php

namespace App\Services\OTA\Flights\Application\DTOs;

final readonly class BookFlightRequest
{
    public function __construct(
        public int         $supplierId,
        public int|string  $branch,
        public array       $bookingData,       // Data array that goes to provider
        public array|false $subData = false,   // lockId, origin, destination, departureDateTime, description
    ) {}
}