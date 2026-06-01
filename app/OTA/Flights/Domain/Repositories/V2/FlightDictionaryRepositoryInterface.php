<?php

namespace App\Services\OTA\Flights\Domain\Repositories\V2;

interface FlightDictionaryRepositoryInterface
{
    public function getAirport(string $iata, bool $details = true): array|string;
    
    public function getAirline(string $iataOrIcao, bool $details = true): array|string;
    
    public function getAircraft(string $idOrIata, bool $details = true): array|string;
    
    public function getSupplier(mixed $systemSupplier, string|int|false $branch = false, bool $isHub = false): array;
}