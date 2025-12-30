<?php

namespace App\Services\FlightProviders;

interface FlightProviderInterface
{

    public function getFlightsSchedule(string $fromDate, string $toDate): array;

    public function getAvailabilityFare(string $origin, string $destination, string $date): array;

    public function getFare(string $origin, string $destination, string $flightClass, string $date, string $flightNo = ''): ?array;

    public function parseAvailableSeats(string $cap, string $flightClass): int;

    public function determineStatus(string $capacity): string;
}