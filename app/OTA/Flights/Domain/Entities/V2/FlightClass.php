<?php

namespace App\Services\OTA\Flights\Domain\Entities\V2;

use App\Services\OTA\Flights\Domain\Enums\CabinType;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\Baggage;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\CancellationPolicy;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\PassengerFare;

final readonly class FlightClass
{
    public function __construct(
        public string $bookingCode,
        public string $fareName,
        public CabinType $cabinType,
        public int $availableSeat,
        public PassengerFare $adultFare,
        public PassengerFare $childFare,
        public PassengerFare $infantFare,
        public Baggage $adultBaggage,
        public Baggage $childBaggage,
        public Baggage $infantBaggage,
        public ?CancellationPolicy $cancellationPolicy,
        public bool $restrictedForTour,
        public ?array $bookingPolicy,
    ) {}
}