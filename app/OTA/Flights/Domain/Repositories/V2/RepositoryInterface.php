<?php

namespace App\Services\OTA\Flights\Domain\Repositories\V2;

use App\Services\OTA\Flights\Domain\Entities\V2\FlightCollection;

interface RepositoryInterface
{
    
    public function searchByRouteAndDate(array $criteria): FlightCollection;

    public function lock(array $data): array;

    public function book(array $data): array;

    public function getStatus(array $data): array;
}