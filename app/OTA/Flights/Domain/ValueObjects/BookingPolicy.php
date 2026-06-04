<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects;

final readonly class BookingPolicy
{
    public function __construct(
        public bool         $restrictedForTour,
        public bool         $returningFlightMustNotEqualToAnyFlight,
        public bool         $returningFlightMustEqualToAnyFlight,
        public bool         $restrictedReturningBySameAirline,
        public array|false  $returningFlightMustEqualList,
        public array|false  $returningFlightMustNotEqualList,
        public int          $fareMinStayDays,
        public int          $fareMaxStayDays,
        public int|false    $returnFlightSupplierId,
    ) {}
}