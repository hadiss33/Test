<?php

namespace App\Services;

use App\Models\{Flight, FlightClass, FlightDetail, FlightRawData, FlightFareBreakdown};
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Log};

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

        $routes = \App\Models\AirlineActiveRoute::where('iata', $this->iata)
            ->where('service', $this->service)
            ->get();
            
        $dates = $this->getDatesForPriority($priority);

        foreach ($routes as $route) {
            foreach ($dates as $date) {
                if (!$route->hasFlightOnDate($date)) {
                    continue;
                }

                try {
                    // دریافت لیست پروازها
                    $flights = $this->provider->getAvailabilityFare(
                        $route->origin,
                        $route->destination,
                        $date->format('Y-m-d')
                    );

                    foreach ($flights as $flightData) {
                        DB::beginTransaction();
                        try {
                            $this->saveFlightWithClasses($route, $flightData, $priority, $date);
                            $stats['checked']++;
                            DB::commit();
                        } catch (\Exception $e) {
                            DB::rollBack();
                            $stats['errors']++;
                            Log::error("Save Flight Error: " . $e->getMessage(), [
                                'flight_no' => $flightData['FlightNo'] ?? 'unknown',
                                'route' => "{$route->origin}-{$route->destination}"
                            ]);
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

    protected function saveFlightWithClasses($route, array $data, int $priority, Carbon $date): void
    {
        $departureDateTime = Carbon::parse($data['DepartureDateTime']);
        
        // 1. ذخیره/به‌روزرسانی Flight اصلی
        $flight = Flight::updateOrCreate(
            [
                'airline_active_route_id' => $route->id,
                'flight_number' => $data['FlightNo'],
                'departure_datetime' => $departureDateTime,
            ],
            [
                'aircraft_type' => $data['AircraftTypeCode'] ?? null,
                'update_priority' => $priority,
                'last_updated_at' => now(),
            ]
        );


        FlightDetail::updateOrCreate(
            ['flight_id' => $flight->id],
            [
                'arrival_datetime' => isset($data['ArrivalDateTime']) 
                    ? Carbon::parse($data['ArrivalDateTime']) 
                    : null,
                'has_transit' => $data['Transit'] ?? false,
            ]
        );

        foreach ($data['ClassesStatus'] as $classData) {
            $this->saveFlightClass($flight, $route, $classData, $date);
        }
    }

    protected function saveFlightClass(Flight $flight, $route, array $classData, Carbon $date): void
    {
        $cap = $classData['Cap'];
        $classCode = $classData['FlightClass'];
        
        $fareData = $this->provider->getFare(
            $route->origin,
            $route->destination,
            $classCode,
            $date->format('Y-m-d'),
            $flight->flight_number
        );

        // ذخیره FlightClass
        $flightClass = FlightClass::updateOrCreate(
            [
                'flight_id' => $flight->id,
                'class_code' => $classCode,
            ],
            [
                'class_status' => $cap,
                'price_adult' => $fareData['AdultTotalPrice'] ?? 0,
                'price_child' => $fareData['ChildTotalPrice'] ?? 0,
                'price_infant' => $fareData['InfantTotalPrice'] ?? 0,
                'available_seats' => $this->provider->parseAvailableSeats($cap),
                'status' => $this->provider->determineStatus($cap),
                'last_updated_at' => now(),
            ]
        );

        // ذخیره Fare Breakdown
        if ($fareData) {
            $this->saveFareBreakdown($flightClass, $fareData);
        }
    }

    protected function saveFareBreakdown(FlightClass $flightClass, array $fareData): void
    {
        $passengerTypes = [
            'adult' => [
                'base' => 'AdultFare',
                'taxes' => 'AdultTaxes',
                'total' => 'AdultTotalPrice'
            ],
            'child' => [
                'base' => 'ChildFare',
                'taxes' => 'ChildTaxes',
                'total' => 'ChildTotalPrice'
            ],
            'infant' => [
                'base' => 'InfantFare',
                'taxes' => 'InfantTaxes',
                'total' => 'InfantTotalPrice'
            ]
        ];

        foreach ($passengerTypes as $type => $keys) {
            if (!isset($fareData[$keys['base']])) continue;

            $taxes = $this->parseTaxes($fareData[$keys['taxes']] ?? '');
            
            FlightFareBreakdown::updateOrCreate(
                [
                    'flight_class_id' => $flightClass->id,
                    'passenger_type' => $type,
                ],
                [
                    'base_fare' => $fareData[$keys['base']],
                    'tax_i6' => $taxes['I6'] ?? 0,
                    'tax_v0' => $taxes['V0'] ?? 0,
                    'tax_hl' => $taxes['HL'] ?? 0,
                    'tax_lp' => $taxes['LP'] ?? 0,
                    'total_price' => $fareData[$keys['total']],
                    'last_updated_at' => now(),
                ]
            );
        }
    }

    protected function parseTaxes(string $taxString): array
    {
        $taxes = [];
        
        $parts = explode('$', $taxString);
        
        foreach ($parts as $part) {
            if (preg_match('/([A-Z0-9]+):([0-9.]+)/', $part, $matches)) {
                $taxes[$matches[1]] = (float) $matches[2];
            }
        }
        
        return $taxes;
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

        [$start, $end] = $ranges[$priority] ?? [0, 3];

        for ($i = $start; $i <= $end; $i++) {
            $dates[] = now()->addDays($i);
        }

        return $dates;
    }
}