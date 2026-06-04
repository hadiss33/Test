<?php

namespace App\Services\OTA\Flights\Application\DTOs;

final readonly class SearchFlightRequest
{
    public function __construct(
        public string      $originIata,
        public string      $destinationIata,
        public string      $departureDate,     // Y-m-d
        public int         $adultCount,
        public int         $childCount,
        public int         $infantCount,
        public int|string  $branch,
        public bool        $withDetails,
        public bool        $fetchSupplierWebserviceFlights = false,
        /** @var int[]|false $supplierIds فقط وقتی suppliers از بیرون inject می‌شند */
        public array|false $supplierIds = false,
    ) {}
}