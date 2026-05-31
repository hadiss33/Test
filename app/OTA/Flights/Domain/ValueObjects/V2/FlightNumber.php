<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects\V2;

final readonly class FlightNumber
{
    public function __construct(
        public string $number,
        public string $airlineIata,
    ) {}

    public function full(): string
    {
        return $this->airlineIata . $this->number;
    }
}