<?php

namespace App\Services\OTA\Flights\Domain\Entities\V2;

use App\Services\OTA\Flights\Domain\Enums\FlightType;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\AirportCode;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\Date;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\Duration;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\FlightNumber;

final class Flight
{
    /** @var FlightClass[] */
    private array $classes = [];

    public function __construct(
        public readonly string $serviceId,
        public readonly string $serviceBranch,
        public readonly bool $isHub,
        public readonly FlightType $flightType,
        public readonly FlightNumber $flightNumber,
        public readonly AirportCode $origin,
        public readonly AirportCode $destination,
        public readonly Date $departureDateTime,
        public readonly Date $arrivalDateTime,
        public readonly Duration $duration,
        public readonly string $aircraftIata,
        public readonly string $airlineIata,
        public readonly ?string $remarks,
        public readonly bool $isOwnedBySupplier,
        public readonly ?array $stop1,
        public readonly ?array $stop2,
        public readonly array $permittedNationalities,
        public readonly array $prohibitedNationalities,
    ) {}

    public function addClass(FlightClass $class): void
    {
        $this->classes[] = $class;
    }

    public function getClasses(): array
    {
        return $this->classes;
    }
}