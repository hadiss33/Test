<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects;

final readonly class FlightRemarks
{
    public function __construct(
        public bool         $airlineSupplier,
        public bool         $tourRequirement,
        public bool         $oneWayRequirement,
        public bool         $roundtripRequirement,
        public bool         $phoneRequirement,
        public string|false $description,
        public bool         $special,
        public bool         $warranty,
    ) {}
}