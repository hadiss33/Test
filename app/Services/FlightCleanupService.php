<?php

namespace App\Services;

use App\Models\{Flight, FlightClass};
use Illuminate\Support\Facades\{DB, Log};
use Carbon\Carbon;

class FlightCleanupService
{

    public function cleanupPastFlights(): array
    {
        $yesterday = now()->subDay()->endOfDay();

        $deletedCount = Flight::where('departure_datetime', '<', $yesterday)
            ->delete();

        return [
            'deleted_flights' => $deletedCount,
            'cleaned_at' => now()->toDateTimeString()
        ];
    }

    public function handleMissingFlights(): array
    {
        $stats = [
            'marked_pending' => 0,
            'deleted' => 0,
            'errors' => 0
        ];

        $upcomingFlights = Flight::with(['route.applicationInterface', 'classes'])
            ->upcoming()
            ->get()
            ->groupBy('airline_active_route_id');

        foreach ($upcomingFlights as $routeId => $flights) {
            $flight = $flights->first();
            $route = $flight->route;

            try {
                $provider = $this->getProvider($flight->iata, $route->applicationInterface->service);

                foreach ($flights as $flight) {
                    $date = $flight->departure_datetime->format('Y-m-d');

                    $apiFlights = $provider->getAvailabilityFare(
                        $route->origin,
                        $route->destination,
                        $date
                    );

                    $foundInApi = collect($apiFlights)->contains(function ($apiF) use ($flight) {
                        return $apiF['FlightNo'] == $flight->flight_number
                            && Carbon::parse($apiF['DepartureDateTime'])->eq($flight->departure_datetime);
                    });

                    if (!$foundInApi) {
                        $missingCount = $flight->missing_count ?? 0;

                        if ($missingCount >= 2) {
                            $flight->delete();
                            $stats['deleted']++;

                            Log::warning("Flight deleted after 2 missing checks", [
                                'flight_number' => $flight->flight_number,
                                'route' => "{$route->origin}-{$route->destination}",
                                'date' => $date
                            ]);
                        } else {
                            $flight->update(['missing_count' => $missingCount + 1]);

                            $flight->classes()->update(['status' => 'closed']);

                            $stats['marked_pending']++;
                        }
                    } else {
                        if (isset($flight->missing_count) && $flight->missing_count > 0) {
                            $flight->update(['missing_count' => 0]);
                        }
                    }
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error("Error checking missing flights for route {$routeId}: " . $e->getMessage());
            }
        }

        return $stats;
    }


    protected function getProvider(string $iata, string $service)
    {
        $repository = app(\App\Repositories\Contracts\FlightServiceRepositoryInterface::class);
        $config = $repository->getServiceByCode($service, $iata);

        return new \App\Services\FlightProviders\NiraProvider($config);
    }
}
