<?php

namespace App\Services\OTA\Flights\Application\DTOs\V2;

final readonly class Request
{
    public function __construct(
        public string $originIataCode,
        public string $destinationIataCode,
        public string $departureDate, // Y-m-d
        public ?string $returningDate = null, // Y-m-d or null
        public bool $fetchSupplierWebserviceFlights = false,
        public bool $fetchFlightsWithBookingPolicy = true,
        public string $language = 'FA',
        public bool $details = false,
        public string|int|false $branch = false,
    ) {}

    public function isRoundTrip(): bool
    {
        return $this->returningDate !== null && $this->returningDate !== '';
    }

    public function toSepehrPayload(): array
    {
        $originDestinationOptions = [
            [
                'OriginIataCode' => $this->originIataCode,
                'DestinationIataCode' => $this->destinationIataCode,
                'FlightDate' => $this->departureDate,
            ],
        ];

        if ($this->isRoundTrip()) {
            $originDestinationOptions[] = [
                'OriginIataCode' => $this->destinationIataCode,
                'DestinationIataCode' => $this->originIataCode,
                'FlightDate' => $this->returningDate,
            ];
        }

        return [
            'OriginDestinationOptionList' => $originDestinationOptions,
            'FetchFlightsThatAreNotOwnedBySupplier' => false,
            'FetchFlightsThatAreRestrictedForTour' => false,
            'FetchClosedFlights' => false,
            'Language' => $this->language,
        ];
    }
}