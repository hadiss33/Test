<?php

namespace App\Services\OTA\Flights\Application\UseCases;

use App\Services\OTA\Flights\Application\Contracts\FlightProviderInterface;
use App\Services\OTA\Flights\Application\DTOs\SearchFlightRequest;
use App\Services\OTA\Flights\Application\DTOs\SearchFlightResult;
use Throwable;
use Illuminate\Support\Facades\Log;

class SearchFlightsUseCase
{

    public function __construct(
        private readonly FlightProviderInterface $flightProvider
    ) {}

    /**
     * اجرای عملیات اصلی جستجو
     */
    public function execute(SearchFlightRequest $request): SearchFlightResult
    {
        try {

            return $this->flightProvider->search($request);

        } catch (Throwable $e) {
            Log::error('SearchFlightsUseCase Error:', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}