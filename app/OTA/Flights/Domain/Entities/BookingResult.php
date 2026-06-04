<?php

namespace App\Services\OTA\Flights\Domain\Entities;

final readonly class BookingResult
{
    public function __construct(
        public bool         $status,
        public string       $localPnr,
        public string       $originIata,
        public string       $destinationIata,
        public bool         $originTerminal,
        public bool         $destinationTerminal,
        public string       $flightDateTime,
        public string       $description,
        /** @var PassengerTicket[] */
        public array        $passengers,
    ) {}
}