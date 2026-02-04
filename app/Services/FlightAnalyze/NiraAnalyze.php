<?php

namespace App\Services\FlightAnalyze;

use Carbon\Carbon;

class NiraAnalyze
{
    public function adaptFlightsToRoutes(array $flights, ?int $id, ?string $iata): array
    {
        $routes = [];

        foreach ($flights as $flight) {
            $origin = $flight['Origin'];
            $destination = $flight['Destination'];
            $date = Carbon::parse($flight['DepartureDateTime']);
            $dayName = strtolower($date->englishDayOfWeek);

            $key = ($iata ?? '').":{$origin}-{$destination}";

            if (! isset($routes[$key])) {
                $routes[$key] = [
                    'origin' => $origin,
                    'destination' => $destination,
                    'application_interfaces_id' => $id ?? null,
                    'monday' => null, 'tuesday' => null, 'wednesday' => null,
                    'thursday' => null, 'friday' => null, 'saturday' => null, 'sunday' => null,
                ];
            }

            $routes[$key][$dayName] = true;
        }

        return $routes;
    }
}
