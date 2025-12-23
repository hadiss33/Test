<?php

namespace App\Services;

use App\Models\{AirlineActiveRoute, Flight};
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FlightUpdateService
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

    public function updateByPriority(int $priority): array
    {
        $stats = ['checked' => 0, 'updated' => 0, 'errors' => 0];

        $routes = AirlineActiveRoute::where('iata', $this->iata)
            ->where('service', $this->service)
            ->get();
            
        $dates = $this->getDatesForPriority($priority);

        foreach ($routes as $route) {
            foreach ($dates as $date) {
                if (!$route->hasFlightOnDate($date)) {
                    continue;
                }

                $flights = $this->provider->getAvailabilityFare(
                    $route->origin,
                    $route->destination,
                    $date->format('Y-m-d')
                );

                foreach ($flights as $flightData) {
                    try {
                        $this->saveOrUpdateFlight($route, $flightData, $priority);
                        $stats['checked']++;
                    } catch (\Exception $e) {
                        $stats['errors']++;
                        Log::error("Flight update error: " . $e->getMessage());
                    }
                }
            }
        }

        return $stats;
    }

    protected function getDatesForPriority(int $priority): array
    {
        $dates = [];
        $ranges = [
            1 => [0, 3],
            2 => [4, 7],
            3 => [8, 30],
            4 => [31, 120],
        ];

        [$start, $end] = $ranges[$priority];
        for ($i = $start; $i <= $end; $i++) {
            $dates[] = now()->addDays($i);
        }

        return $dates;
    }

    protected function saveOrUpdateFlight($route, array $data, int $priority): void
    {
        $departureDateTime = Carbon::parse($data['DepartureDateTime']);
        
        foreach ($data['ClassesStatus'] as $classData) {
            $cap = $classData['Cap'];
            
            Flight::updateOrCreate(
                [
                    'airline_active_route_id' => $route->id,
                    'flight_number' => $data['FlightNo'],
                    'flight_date' => $departureDateTime->toDateString(),
                    'flight_class' => $classData['FlightClass'],
                ],
                [
                    'departure_datetime' => $departureDateTime,
                    'class_status' => $cap,
                    'available_seats' => $this->provider->parseAvailableSeats($cap),
                    'price_adult' => $classData['Price'] ?? 0,
                    'price_child' => 0,
                    'price_infant' => 0,
                    'aircraft_type' => $data['AircraftTypeCode'] ?? null,
                    'currency' => $classData['CurrencyCode'] ?? 'IRR',
                    'status' => $this->provider->determineStatus($cap),
                    'update_priority' => $priority,
                    'last_updated_at' => now(),
                    'raw_data' => $data,
                ]
            );
        }
    }
}