<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects;

final readonly class Stopover
{
    public function __construct(
        public string $airportIata,
        public int    $stopDurationMinutes,
        public string $arrivalDateTime,
        public string $departureDateTime,
    ) {}
}