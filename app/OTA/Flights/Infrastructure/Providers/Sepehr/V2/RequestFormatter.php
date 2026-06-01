<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2;

use App\Services\OTA\Flights\Application\DTOs\V2\FlightSegmentDTO;
use App\Services\OTA\Flights\Application\DTOs\V2\LockRequest;
use App\Services\OTA\Flights\Application\DTOs\V2\SearchRequest;

final class RequestFormatter
{
    public static function formatSearch(SearchRequest $request): array
    {
        $options = [
            [
                'OriginIataCode' => $request->originIataCode,
                'DestinationIataCode' => $request->destinationIataCode,
                'FlightDate' => $request->departureDate->toDateString(),
            ],
        ];

        if ($request->returningDate !== null) {
            $options[] = [
                'OriginIataCode' => $request->destinationIataCode, // جای مبدا و مقصد عوض می‌شود
                'DestinationIataCode' => $request->originIataCode,
                'FlightDate' => $request->returningDate->toDateString(),
            ];
        }

        return [
            'OriginDestinationOptionList' => $options,
            'FetchFlightsThatAreNotOwnedBySupplier' => false,
            'FetchFlightsThatAreRestrictedForTour' => false,
            'FetchClosedFlights' => false,
            'Language' => 'FA',
        ];
    }

    public static function formatLock(LockRequest $request): array
    {
        $formatSegment = fn (FlightSegmentDTO $segment) => [
            'FlightNumber' => $segment->flightNumber,
            'FlightDate' => $segment->flightDate, // مثال: 2024-04-14 11:00
            'OriginIataCode' => $segment->originIataCode,
            'DestinationIataCode' => $segment->destinationIataCode,
            'FareName' => $segment->fareName,
        ];

        return [
            'DepartureSegment' => $formatSegment($request->departureSegment),
            'ReturningSegment' => $request->returningSegment ? $formatSegment($request->returningSegment) : null,
            'AdultCount' => $request->adultCount,
            'ChildCount' => $request->childCount,
            'InfantCount' => $request->infantCount,
            'TotalPayable' => $request->totalPayable,
        ];
    }
}
