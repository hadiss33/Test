<?php

namespace App\Services\OTA\Flights\Domain\Entities;

use App\Services\OTA\Flights\Domain\Enums\FlightRoute;
use App\Services\OTA\Flights\Domain\Enums\FlightType;
use App\Services\OTA\Flights\Domain\ValueObjects\FlightRemarks;
use App\Services\OTA\Flights\Domain\ValueObjects\Stopover;

final readonly class Flight
{
    public function __construct(
        public string              $service,           // 'sepehr'
        public int                 $serviceId,
        public int                 $serviceBranch,
        public string              $displayableService,
        public bool                $verified,
        public FlightType          $flightType,
        public FlightRoute         $flightRoute,
        public string              $flightNumber,
        public array               $origin,            // ['iata' => [...], 'terminal' => bool]
        public array               $destination,
        public string              $departureDateTime,
        public string              $arrivalDateTime,
        public int                 $duration,
        public array               $aircraft,
        public array               $airline,
        public FlightRemarks       $remarks,
        public array|false         $returningFlight,
        /** @var Stopover[] */
        public array|false         $steps,
        /** @var FlightClass[] */
        public array               $classes,
    ) {}
}