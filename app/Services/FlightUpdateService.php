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

                try {
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
                            Log::error("Save Flight Error: " . $e->getMessage());
                        }
                    }

                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::error("Fetch Availability Error [{$route->origin}-{$route->destination}]: " . $e->getMessage());
                }
            }
        }

        return $stats;
    }


    protected function getDatesForPriority(int $priority): array
    {
        $dates = [];
        $start = 0;
        $end = 0;

        switch ($priority) {
            case 1: 
                $start = 0; $end = 3;
                break;
            case 2: 
                $start = 4; $end = 7;
                break;
            case 3: 
                $start = 8; $end = 30;
                break;
            case 4: 
                $start = 31; $end = 120;
                break;
            default:
                $start = 0; $end = 3;
        }

        for ($i = $start; $i <= $end; $i++) {
            $dates[] = now()->addDays($i);
        }

        return $dates;
    }


    protected function saveOrUpdateFlight($route, array $data, int $priority): void
    {
        $departureDateTime = Carbon::parse($data['DepartureDateTime']);
        $flightDate = $departureDateTime->toDateString();
        
        foreach ($data['ClassesStatus'] as $classData) {
            $cap = $classData['Cap'];
            
            $price = (isset($classData['Price']) && is_numeric($classData['Price'])) 
                ? $classData['Price'] 
                : 0;

            Flight::updateOrCreate(
                [
                    'airline_active_route_id' => $route->id,
                    'flight_number' => $data['FlightNo'],
                    'flight_class' => $classData['FlightClass'],
                    'flight_date' => $flightDate, 
                ],
                [
                    'departure_datetime' => $departureDateTime,
                    'class_status' => $cap,
                    'available_seats' => $this->provider->parseAvailableSeats($cap),
                    
                    'price_adult' => $price,
                    'price_child' => 0,
                    'price_infant' => 0, 
                    
                    'aircraft_type' => $data['AircraftTypeCode'] ?? null,
                    
                    
                    'status' => $this->provider->determineStatus($cap),
                    'update_priority' => $priority,
                    'last_updated_at' => now(),
                    'raw_data' => $data,
                ]
            );
        }
    }
}