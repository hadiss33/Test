<?php

namespace App\Services;

use App\Enums\ServiceProviderEnum;
use App\Services\FlightProviders\FlightProviderInterface;
use Illuminate\Support\Facades\Log;

class RouteSyncService
{
    protected $provider;

    protected $iata;

    protected $service;

    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service = 'nira')
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

        $interface = $this->provider->getConfig();
        $normalayzerClass = ServiceProviderEnum::from($this->service)->getAnalyzer();
        $routes = (new $normalayzerClass)->adaptFlightsToRoutes($flights, $interface['id']);
        if (empty($flights)) {
            return ['success' => false, 'message' => 'No flights returned'];
        }

        $this->cleanupOldRoutes(array_keys($routes));
        try {
            $this->saveRoutes($routes);

        } catch (\Exception $e) {
            Log::error('Error saving routes: '.$e->getMessage());
        }

        return [
            'success' => true,
            'routes_count' => count($routes),
            'flights_analyzed' => count($flights),
        ];
    }

    protected function cleanupOldRoutes(array $activeRouteKeys): void
    {
        $fullConfig = $this->provider->getConfig();

        $existingRoutes = \App\Models\AirlineActiveRoute::where('application_interfaces_id', $fullConfig['id'] ?? null)
            ->get();

        foreach ($existingRoutes as $route) {
            $key = "{$route->application_interfaces_id}:{$route->origin}-{$route->destination}";

            if (! in_array($key, $activeRouteKeys)) {
                Log::info("Deleting inactive route: {$key}");
                $route->delete();
            }
        }
    }

    protected function saveRoutes(array $routes): void
    {

        foreach ($routes as $routeData) {
            try {
                \App\Models\AirlineActiveRoute::updateOrCreate(
                    [
                        'origin' => $routeData['origin'],
                        'destination' => $routeData['destination'],
                        'application_interfaces_id' => $routeData['application_interfaces_id'],
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
            } catch (\Exception $e) {
                Log::error('Error saving route: '.$e->getMessage());
            }

        }
    }
}
