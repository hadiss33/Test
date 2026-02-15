<?php

namespace App\Services\FlightUpdaters;

use Carbon\Carbon;

class RavisAnalyze
{

    public function adaptFlightsToRoutes(array $flights, ?int $id): array
    {
        $routes = [];

        foreach ($flights as $flight) {
            $origin = $flight['IataCodSource'] ?? null;
            $destination = $flight['IataCodDestinate'] ?? null;
            $airlineCode = $flight['AirlineCode'] ?? null;

            if (!$origin || !$destination || !$airlineCode) {
                continue;
            }
            
            try {
                $date = Carbon::parse($flight['FlightDateM']);
                $dayName = strtolower($date->englishDayOfWeek);
            } catch (\Exception $e) {
                continue; 
            }

            $key = "{$id}:{$origin}-{$destination}";

            if (!isset($routes[$key])) {
                $routes[$key] = [
                    'origin' => $origin,
                    'destination' => $destination,
                    'application_interfaces_id' => $id ?? null,
                    'monday' => null,
                    'tuesday' => null,
                    'wednesday' => null,
                    'thursday' => null,
                    'friday' => null,
                    'saturday' => null,
                    'sunday' => null,
                ];
            }

            $routes[$key][$dayName] = true;
        }

        return $routes;
    }
}