<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects;

final readonly class RoundtripFare
{
    public function __construct(
        public FarePrice $fromOriginAdult,
        public FarePrice $fromOriginChild,
        public FarePrice $fromOriginInfant,
        public FarePrice $fromDestinationAdult,
        public FarePrice $fromDestinationChild,
        public FarePrice $fromDestinationInfant,
    ) {}
}