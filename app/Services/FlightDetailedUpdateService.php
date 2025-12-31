<?php

namespace App\Services;

use App\Models\{Flight, FlightClass, FlightDetail, FlightFareBreakdown, AirlineActiveRoute};
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Log};

class FlightDetailedUpdateService
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
        $stats = [
            'checked' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'fare_calls' => 0,
            'fare_success' => 0,
            'fare_failed' => 0,
        ];

        $routes = AirlineActiveRoute::where('iata', $this->iata)
            ->where('service', $this->service)
            ->get();
            
        $dates = $this->getDatesForPriority($priority);

        Log::info("Starting detailed update for {$this->iata}", [
            'priority' => $priority,
            'routes_count' => $routes->count(),
            'dates_count' => count($dates)
        ]);

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

                    Log::info("Fetched flights for route", [
                        'route' => "{$route->origin}-{$route->destination}",
                        'date' => $date->format('Y-m-d'),
                        'flights_count' => count($flights)
                    ]);

                    foreach ($flights as $flightData) {
                        DB::beginTransaction();
                        try {
                            $updateResult = $this->saveFlightWithAllDetails(
                                $route,
                                $flightData,
                                $priority,
                                $date
                            );
                            
                            $stats['checked']++;
                            $stats[$updateResult['status']]++;
                            $stats['fare_calls'] += $updateResult['fare_calls'];
                            $stats['fare_success'] += $updateResult['fare_success'];
                            $stats['fare_failed'] += $updateResult['fare_failed'];
                            
                            DB::commit();
                        } catch (\Exception $e) {
                            DB::rollBack();
                            $stats['errors']++;
                            Log::error("Save Detailed Flight Error: " . $e->getMessage(), [
                                'flight_no' => $flightData['FlightNo'] ?? 'unknown',
                                'route' => "{$route->origin}-{$route->destination}",
                                'date' => $date->format('Y-m-d'),
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

        Log::info("Detailed update completed", $stats);

        return $stats;
    }

    protected function saveFlightWithAllDetails($route, array $data, int $priority, Carbon $date): array
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

        // پیدا کردن اولین کلاس برای گرفتن اطلاعات details
        $firstClass = null;
        $fareDataForDetails = null;
        
        if (!empty($data['ClassesStatus'])) {
            // اولین کلاس را انتخاب می‌کنیم (مهم نیست فعال باشه یا نه)
            $firstClassData = $data['ClassesStatus'][0];
            $firstClass = $firstClassData['FlightClass'];
            
            Log::info("Fetching Fare for flight details", [
                'flight_no' => $data['FlightNo'],
                'class' => $firstClass,
                'route' => "{$route->origin}-{$route->destination}"
            ]);
            
            $fareDataForDetails = $this->provider->getFare(
                $route->origin,
                $route->destination,
                $firstClass,
                $date->format('Y-m-d'),
                $flight->flight_number
            );
            
            if ($fareDataForDetails) {
                Log::info("Fare API success for details", [
                    'flight_no' => $data['FlightNo'],
                    'fare_keys' => array_keys($fareDataForDetails)
                ]);
            } else {
                Log::warning("Fare API returned null for details", [
                    'flight_no' => $data['FlightNo'],
                    'class' => $firstClass
                ]);
            }
        }

        $this->saveFlightDetails($flight, $data, $fareDataForDetails);

        $fareCallsCount = $firstClass ? 1 : 0;
        $fareSuccessCount = $fareDataForDetails ? 1 : 0;
        $fareFailedCount = $firstClass && !$fareDataForDetails ? 1 : 0;
        $hasChanges = false;
        
        foreach ($data['ClassesStatus'] as $classData) {
            $result = $this->saveFlightClassWithDetails(
                $flight,
                $route,
                $classData,
                $date
            );
            
            if ($result['changed']) {
                $hasChanges = true;
            }
            $fareCallsCount += $result['fare_calls'];
            $fareSuccessCount += $result['fare_success'];
            $fareFailedCount += $result['fare_failed'];
        }

        return [
            'status' => $isNew ? 'updated' : ($hasChanges ? 'updated' : 'skipped'),
            'fare_calls' => $fareCallsCount,
            'fare_success' => $fareSuccessCount,
            'fare_failed' => $fareFailedCount,
        ];
    }

    protected function saveFlightDetails(Flight $flight, array $availabilityData, ?array $fareData): void
    {
        Log::info("Saving flight details", [
            'flight_id' => $flight->id,
            'has_fareData' => $fareData !== null,
            'fareData_keys' => $fareData ? array_keys($fareData) : []
        ]);

        $detailData = [
            'arrival_datetime' => isset($availabilityData['ArrivalDateTime']) 
                ? Carbon::parse($availabilityData['ArrivalDateTime']) 
                : null,
            'has_transit' => $availabilityData['Transit'] ?? false,
            'transit_city' => null,
            'operating_airline' => null,
            'operating_flight_no' => null,
            'refund_rules' => $fareData['CRCNRules'] ?? null,
            'baggage_weight' => $fareData['BaggageAllowanceWeight'] ?? null,
            'baggage_pieces' => $fareData['BaggageAllowancePieces'] ?? null,
        ];

        Log::info("Flight detail data to save", [
            'flight_id' => $flight->id,
            'refund_rules_length' => $detailData['refund_rules'] ? strlen($detailData['refund_rules']) : 0,
            'baggage_weight' => $detailData['baggage_weight'],
            'baggage_pieces' => $detailData['baggage_pieces'],
        ]);

        FlightDetail::updateOrCreate(
            ['flight_id' => $flight->id],
            $detailData
        );
    }

    protected function saveFlightClassWithDetails(
        Flight $flight,
        $route,
        array $classData,
        Carbon $date
    ): array {
        $cap = $classData['Cap'];
        $classCode = $classData['FlightClass'];
        $fareCallsCount = 0;
        $fareSuccessCount = 0;
        $fareFailedCount = 0;
        
        $status = $this->provider->determineStatus($cap);
        $availableSeats = $this->provider->parseAvailableSeats($cap, $classCode);
        
        Log::info("Processing class", [
            'flight_no' => $flight->flight_number,
            'class' => $classCode,
            'cap' => $cap,
            'status' => $status,
            'seats' => $availableSeats
        ]);

        $fareData = null;
        $priceAdult = 0;
        $priceChild = 0;
        $priceInfant = 0;

        // همیشه Fare API را صدا می‌زنیم، بدون توجه به status
        $price = $classData['Price'] ?? '0';
        
        if ($price !== '-' && is_numeric($price) && $price > 0) {
            $priceAdult = (float) $price;
        }

        // حالا همیشه Fare API را صدا می‌زنیم
        Log::info("Calling Fare API for class", [
            'flight_no' => $flight->flight_number,
            'class' => $classCode
        ]);

        $fareData = $this->provider->getFare(
            $route->origin,
            $route->destination,
            $classCode,
            $date->format('Y-m-d'),
            $flight->flight_number
        );
        
        $fareCallsCount++;
        
        if ($fareData) {
            $fareSuccessCount++;
            $priceAdult = $fareData['AdultTotalPrice'] ?? $priceAdult;
            $priceChild = $fareData['ChildTotalPrice'] ?? 0;
            $priceInfant = $fareData['InfantTotalPrice'] ?? 0;
            
            Log::info("Fare API success for class", [
                'flight_no' => $flight->flight_number,
                'class' => $classCode,
                'adult' => $priceAdult,
                'child' => $priceChild,
                'infant' => $priceInfant
            ]);
        } else {
            $fareFailedCount++;
            Log::warning("Fare API failed for class", [
                'flight_no' => $flight->flight_number,
                'class' => $classCode,
                'cap' => $cap,
                'status' => $status
            ]);
        }

        $newData = [
            'class_status' => $cap,
            'price_adult' => $priceAdult,
            'price_child' => $priceChild,
            'price_infant' => $priceInfant,
            'available_seats' => $availableSeats,
            'status' => $status,
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
            
            return [
                'changed' => true,
                'fare_calls' => $fareCallsCount,
                'fare_success' => $fareSuccessCount,
                'fare_failed' => $fareFailedCount,
            ];
        }

        $hasChanges = 
            $flightClass->class_status != $newData['class_status'] ||
            $flightClass->price_adult != $newData['price_adult'] ||
            $flightClass->price_child != $newData['price_child'] ||
            $flightClass->price_infant != $newData['price_infant'] ||
            $flightClass->available_seats != $newData['available_seats'] ||
            $flightClass->status != $newData['status'];

        if ($hasChanges) {
            $flightClass->update($newData);
        }

        if ($fareData) {
            $this->saveFareBreakdown($flightClass, $fareData);
        }

        return [
            'changed' => $hasChanges,
            'fare_calls' => $fareCallsCount,
            'fare_success' => $fareSuccessCount,
            'fare_failed' => $fareFailedCount,
        ];
    }

    protected function saveFareBreakdown(FlightClass $flightClass, array $fareData): void
    {
        $passengerTypes = [
            'adult' => ['base' => 'AdultFare', 'taxes' => 'AdultTaxes', 'total' => 'AdultTotalPrice'],
            'child' => ['base' => 'ChildFare', 'taxes' => 'ChildTaxes', 'total' => 'ChildTotalPrice'],
            'infant' => ['base' => 'InfantFare', 'taxes' => 'InfantTaxes', 'total' => 'InfantTotalPrice']
        ];

        foreach ($passengerTypes as $type => $keys) {
            if (!isset($fareData[$keys['base']])) {
                Log::info("Skipping fare breakdown for {$type} - no base fare", [
                    'class_id' => $flightClass->id,
                    'available_keys' => array_keys($fareData)
                ]);
                continue;
            }

            $taxes = $this->parseTaxes($fareData[$keys['taxes']] ?? '');
            
            $breakdownData = [
                'base_fare' => $fareData[$keys['base']],
                'tax_i6' => $taxes['I6'] ?? 0,
                'tax_v0' => $taxes['V0'] ?? 0,
                'tax_hl' => $taxes['HL'] ?? 0,
                'tax_lp' => $taxes['LP'] ?? 0,
                'total_price' => $fareData[$keys['total']],
                'last_updated_at' => now(),
            ];

            Log::info("Saving fare breakdown for {$type}", [
                'class_id' => $flightClass->id,
                'data' => $breakdownData
            ]);

            FlightFareBreakdown::updateOrCreate(
                [
                    'flight_class_id' => $flightClass->id,
                    'passenger_type' => $type
                ],
                $breakdownData
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