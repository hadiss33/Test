<?php

namespace App\Services;

use App\Models\AirlineActiveRoute;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RouteSyncService
{
    protected $provider;
    protected $iata;
    protected $service;

    public function __construct(FlightProviderInterface $provider, string $iata, string $service = 'nira')
    {
        $this->provider = $provider;
        $this->iata = $iata;
        $this->service = $service;
    }

    public function sync(): array
    {
        $fromDate = now()->format('Y-m-d');
        $toDate = now()->addDays(120)->format('Y-m-d');
        
        $flights = $this->provider->getFlightsSchedule($fromDate, $toDate);
        
        if (empty($flights)) {
            return ['success' => false, 'message' => 'No flights returned'];
        }

        $routes = $this->analyzeRoutes($flights);
        $this->cleanupOldRoutes(array_keys($routes));
        $this->saveRoutes($routes);

        return [
            'success' => true,
            'routes_count' => count($routes),
            'flights_analyzed' => count($flights)
        ];
    }

    protected function analyzeRoutes(array $flights): array
    {
        $routes = [];

        foreach ($flights as $flight) {
            $origin = $flight['Origin'];
            $destination = $flight['Destination'];
            $date = Carbon::parse($flight['DepartureDateTime']);
            $dayName = strtolower($date->englishDayOfWeek);

            $key = "{$this->iata}:{$origin}-{$destination}";
            
            if (!isset($routes[$key])) {
                $routes[$key] = [
                    'origin' => $origin,
                    'destination' => $destination,
                    'monday' => 0, 'tuesday' => 0, 'wednesday' => 0,
                    'thursday' => 0, 'friday' => 0, 'saturday' => 0, 'sunday' => 0,
                ];
            }

            $routes[$key][$dayName]++;
        }

        return $routes;
    }

    protected function cleanupOldRoutes(array $activeRouteKeys): void
    {
        $existingRoutes = AirlineActiveRoute::where('iata', $this->iata)
            ->where('service', $this->service)
            ->get();
        
        foreach ($existingRoutes as $route) {
            $key = "{$route->iata}:{$route->origin}-{$route->destination}";
            
            if (!in_array($key, $activeRouteKeys)) {
                Log::info("Deleting inactive route: {$key}");
                $route->delete();
            }
        }
    }

    protected function saveRoutes(array $routes): void
    {
        foreach ($routes as $routeData) {
            AirlineActiveRoute::updateOrCreate(
                [
                    'iata' => $this->iata,
                    'origin' => $routeData['origin'],
                    'destination' => $routeData['destination'],
                    'service' => $this->service,
                ],
                [
                    'monday' => $routeData['monday'],
                    'tuesday' => $routeData['tuesday'],
                    'wednesday' => $routeData['wednesday'],
                    'thursday' => $routeData['thursday'],
                    'friday' => $routeData['friday'],
                    'saturday' => $routeData['saturday'],
                    'sunday' => $routeData['sunday'],
                ]
            );
        }
    }
}