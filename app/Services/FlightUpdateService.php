<?php

namespace App\Services;

use App\Models\{Flight, FlightClass, FlightDetail, FlightFareBreakdown};
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
        $stats = ['checked' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

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
                    $flights = $this->provider->getAvailabilityFare(
                        $route->origin,
                        $route->destination,
                        $date->format('Y-m-d')
                    );

                    foreach ($flights as $flightData) {
                        DB::beginTransaction();
                        try {
                            $updateResult = $this->saveFlightWithClasses($route, $flightData, $priority, $date);
                            
                            $stats['checked']++;
                            $stats[$updateResult]++;
                            
                            DB::commit();
                        } catch (\Exception $e) {
                            DB::rollBack();
                            $stats['errors']++;
                            Log::error("Save Flight Error: " . $e->getMessage(), [
                                'flight_no' => $flightData['FlightNo'] ?? 'unknown',
                                'route' => "{$route->origin}-{$route->destination}",
                                'trace' => $e->getTraceAsString()
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

    protected function saveFlightWithClasses($route, array $data, int $priority, Carbon $date): string
    {
        $departureDateTime = Carbon::parse($data['DepartureDateTime']);
        
        $flight = Flight::firstOrNew([
            'airline_active_route_id' => $route->id,
            'flight_number' => $data['FlightNo'],
            'departure_datetime' => $departureDateTime,
        ]);

        $isNew = !$flight->exists;
        
        $flight->aircraft_type = $data['AircraftTypeCode'] ?? null;
        $flight->update_priority = $priority;
        $flight->last_updated_at = now();
        $flight->missing_count = 0;
        $flight->save();

        FlightDetail::updateOrCreate(
            ['flight_id' => $flight->id],
            [
                'arrival_datetime' => isset($data['ArrivalDateTime']) 
                    ? Carbon::parse($data['ArrivalDateTime']) 
                    : null,
                'has_transit' => $data['Transit'] ?? false,
                'last_updated_at' => now(),
            ]
        );

        $hasChanges = false;
        
        foreach ($data['ClassesStatus'] as $classData) {
            $changed = $this->saveFlightClass($flight, $route, $classData, $date);
            if ($changed) $hasChanges = true;
        }

        return $isNew ? 'updated' : ($hasChanges ? 'updated' : 'skipped');
    }

    protected function saveFlightClass(Flight $flight, $route, array $classData, Carbon $date): bool
    {

        $cap = $classData['Cap']; 
        $classCode = $classData['FlightClass']; 
        $fareData = null;
        $price = $classData['Price'] ?? '0';
        
        if ($price !== '-' && is_numeric($price) && $price > 0) {
            $priceAdult = (float) $price;
            $priceChild = 0;
            $priceInfant = 0;
        } else {
            $fareData = $this->provider->getFare(
                $route->origin,
                $route->destination,
                $classCode,
                $date->format('Y-m-d'),
                $flight->flight_number
            );
            
            $priceAdult = $fareData['AdultTotalPrice'] ?? 0;
            $priceChild = $fareData['ChildTotalPrice'] ?? 0;
            $priceInfant = $fareData['InfantTotalPrice'] ?? 0;
        }

        $newData = [
            'class_status' => $cap,
            'price_adult' => $priceAdult,
            'price_child' => $priceChild,
            'price_infant' => $priceInfant,
            'available_seats' => $this->provider->parseAvailableSeats($cap , $classCode),
            'status' => $this->provider->determineStatus($cap),
            'last_updated_at' => now(),
        ];

        $flightClass = FlightClass::where('flight_id', $flight->id)
            ->where('class_code', $classCode)
            ->first();

        if (!$flightClass) {
            $flightClass = FlightClass::create(array_merge([
                'flight_id' => $flight->id,
                'class_code' => $classCode,
            ], $newData));

            if ($fareData) {
                $this->saveFareBreakdown($flightClass, $fareData);
            }
            
            return true;
        }

        $hasChanges = 
            $flightClass->class_status != $newData['class_status'] ||
            $flightClass->price_adult != $newData['price_adult'] ||
            $flightClass->available_seats != $newData['available_seats'] ||
            $flightClass->status != $newData['status'];

        if ($hasChanges) {
            $flightClass->update($newData);
            
            // ذخیره Fare Breakdown
            if ($fareData) {
                $this->saveFareBreakdown($flightClass, $fareData);
            }
        }

        return $hasChanges;
    }

    protected function saveFareBreakdown(FlightClass $flightClass, array $fareData): void
    {
        $passengerTypes = [
            'adult' => ['base' => 'AdultFare', 'taxes' => 'AdultTaxes', 'total' => 'AdultTotalPrice'],
            'child' => ['base' => 'ChildFare', 'taxes' => 'ChildTaxes', 'total' => 'ChildTotalPrice'],
            'infant' => ['base' => 'InfantFare', 'taxes' => 'InfantTaxes', 'total' => 'InfantTotalPrice']
        ];

        foreach ($passengerTypes as $type => $keys) {
            if (!isset($fareData[$keys['base']])) continue;

            $taxes = $this->parseTaxes($fareData[$keys['taxes']] ?? '');
            
            FlightFareBreakdown::updateOrCreate(
                ['flight_class_id' => $flightClass->id, 'passenger_type' => $type],
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