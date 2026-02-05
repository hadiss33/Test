<?php

namespace App\Services\FlightUpdaters;

use Carbon\Carbon;

class SepehrAnalyze
{
    public function adaptFlightsToRoutes(array $flights, ?int $id, ?string $iata): array
    {

        
        $routes = [];
        foreach ($flights as $flight) {
            $origin = $flight['OriginIataCode'];
            $destination = $flight['DestinationIataCode'];

            $key = ($iata ?? '').":{$origin}-{$destination}";

            if (! isset($routes[$key])) {
                $routes[$key] = [
                    'origin' => $origin,
                    'destination' => $destination,
                    'application_interfaces_id' => $id ?? null,
                    'monday' => $flight['Monday'] ?: null, 'tuesday' => $flight['Tuesday'] ?: null, 'wednesday' => $flight['Wednesday'] ?: null,
                    'thursday' => $flight['Thursday'] ?: null, 'friday' => $flight['Friday'] ?: null, 'saturday' => $flight['Saturday'] ?: null, 
                    'sunday' => $flight['Sunday'] ?: null,
                ];
            }

        }
                return $routes;

    }
}
