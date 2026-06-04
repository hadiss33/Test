<?php

namespace App\Services\OTA\Flights\Application\Contracts;

use App\Services\OTA\Flights\Application\DTOs\SearchFlightRequest;
use App\Services\OTA\Flights\Application\DTOs\BookFlightRequest;
use App\Services\OTA\Flights\Application\DTOs\SearchFlightResult;
use App\Services\OTA\Flights\Application\DTOs\BookFlightResult;
use App\Services\OTA\Flights\Application\DTOs\BalanceResult;
use App\Services\OTA\Flights\Application\DTOs\ActiveRoutesResult;

interface FlightProviderInterface
{
    public function search(SearchFlightRequest $request): SearchFlightResult;

    public function book(BookFlightRequest $request): BookFlightResult;

    public function getBalance(int $supplierId): BalanceResult;

    public function syncActiveRoutes(int|string $branch): ActiveRoutesResult;

    public function getTransaction(): string;
}