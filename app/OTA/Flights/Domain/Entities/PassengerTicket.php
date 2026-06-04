<?php

namespace App\Services\OTA\Flights\Domain\Entities;

final readonly class PassengerTicket
{
    public function __construct(
        public string $servicePnr,
        public string $originalPnr,
        public string $localTicketNumber,
        public string $originalTicketNumber,
        public string $flightNumber,
    ) {}
}