<?php

namespace App\Services\OTA\Flights\Domain\Repositories;

interface DetailsInterface
{
    /**
     * Get all active API credentials for a branch.
     * Returns collection of credential objects.
     */
    public function getAirportId(string $IataCode): int|null;


}