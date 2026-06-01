<?php

namespace App\Services\OTA\Flights\Application\DTOs\V2;

use App\Services\OTA\Flights\Domain\ValueObjects\V2\Date;

readonly class SearchRequest
{
    public function __construct(
        public string $originIataCode,
        public string $destinationIataCode,
        public Date $departureDate,
        public ?Date $returningDate = null,
        public bool $fetchSupplierWebserviceFlights = false,
        public string $language = 'FA'
    ) {}
}