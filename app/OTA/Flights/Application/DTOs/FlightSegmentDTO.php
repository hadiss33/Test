<?php

namespace App\Services\OTA\Flights\Application\DTOs\V2;

readonly class FlightSegmentDTO
{
    public function __construct(
        public string $flightNumber,
        public string $flightDate, // باید با فرمت Y-m-d H:i پاس داده شود
        public string $originIataCode,
        public string $destinationIataCode,
        public string $fareName
    ) {}
}