<?php

namespace App\Services;

use App\Models\AirlineActiveRoute;
use App\Models\FlightBaggage;
use App\Models\Flight;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Models\FlightFareBreakdown;
use App\Models\FlightRule;
use App\Models\FlightTax;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlightUpdateService
{
    protected $provider;
    protected $iata;
    protected $appInterfaceId;

    public function __construct(FlightProviderInterface $provider, string $iata, string $service = 'nira')
    {
        $this->provider = $provider;
        $this->iata = $iata;
        $this->appInterfaceId = $service;
    }

    public function updateByPriority(int $priority): array
    {
        $fullConfig = $this->provider->getConfig();
        $stats = [
            'checked' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'routes_processed' => 0,
            'flights_found' => 0
        ];

        $routes = AirlineActiveRoute::where('iata', $this->iata)
            ->where('application_interfaces_id', $fullConfig['id'] ?? null)
            ->get();

        if ($routes->isEmpty()) {
            Log::warning("No routes found for airline {$this->iata}");
            return $stats;
        }

        $dates = $this->getDatesForPriority($priority);

        foreach ($routes as $route) {
            $stats['routes_processed']++;
            
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

                    if (empty($flights)) {
                        continue;
                    }

                    $stats['flights_found'] += count($flights);

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
                            
                            Log::error('Save Flight Error', [
                                'flight_no' => $flightData['FlightNo'] ?? 'unknown',
                                'route' => "{$route->origin}-{$route->destination}",
                                'date' => $date->format('Y-m-d'),
                                'error' => $e->getMessage(),
                                'line' => $e->getLine(),
                                'file' => basename($e->getFile()),
                            ]);
                        }
                    }

                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::error("Fetch Availability Error", [
                        'route' => "{$route->origin}-{$route->destination}",
                        'date' => $date->format('Y-m-d'),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $stats;
    }

    protected function saveFlightWithClasses($route, array $data, int $priority, Carbon $date): string
    {
        $departureDateTime = Carbon::parse($data['DepartureDateTime']);

        $flight = Flight::updateOrCreate(
            [
                'airline_active_route_id' => $route->id,
                'flight_number' => $data['FlightNo'],
                'departure_datetime' => $departureDateTime,
            ],
            [
                'updated_at' => now(),
                'missing_count' => null,
            ]
        );

        $isNew = $flight->wasRecentlyCreated;

        FlightDetail::updateOrCreate(
            ['flight_id' => $flight->id],
            [
                'arrival_datetime' => isset($data['ArrivalDateTime']) 
                    ? Carbon::parse($data['ArrivalDateTime']) 
                    : null,
                'aircraft_code' => $data['AircraftCode'] ?? null,
                'aircraft_type_code' => $data['AircraftTypeCode'] ?? null,
                'updated_at' => now(),
            ]
        );

        $hasChanges = false;
        
        if (isset($data['ClassesStatus']) && is_array($data['ClassesStatus'])) {
            foreach ($data['ClassesStatus'] as $classData) {
                $changed = $this->saveFlightClass($flight, $route, $classData, $date);
                if ($changed) {
                    $hasChanges = true;
                }
            }
        }

        return $isNew ? 'updated' : ($hasChanges ? 'updated' : 'skipped');
    }

    protected function saveFlightClass(Flight $flight, $route, array $classData, Carbon $date): bool
    {
        $classCode = $classData['FlightClass'];
        $cap = $classData['Cap'];



        $classUpdateData = [
            'payable_adult' => (float) ($classData['Price'] ?? 0),
            'payable_child' => null,
            'payable_infant' => null,
            'available_seats' => $this->provider->parseAvailableSeats($cap, $classCode),
            'status' => $this->provider->determineStatus($cap),
            'last_updated_at' => now(),
        ];

        $flightClass = FlightClass::updateOrCreate(
            [
                'flight_id' => $flight->id,
                'class_code' => $classCode
            ],
            $classUpdateData
        );



        return $flightClass->wasRecentlyCreated || $flightClass->wasChanged();
    }



    public function getFare(){

        // $fareData = $this->provider->getFare(
        //     $route->origin,
        //     $route->destination,
        //     $classCode,
        //     $date->format('Y-m-d'),
        //     (string) $flight->flight_number
        // );



        // if ($hasFareData) {
        //     $this->saveFareBreakdown($flightClass, $fareData);
        //     $this->saveDetailedTaxes($flightClass, $fareData['Taxes'] ?? []);
        //     $this->saveBaggage($flightClass, $fareData);
        //     $this->saveRules($flightClass, $fareData);
        // }
    }

    protected function saveFareBreakdown(FlightClass $flightClass, array $fareData): void
    {
        FlightFareBreakdown::updateOrCreate(
            ['flight_class_id' => $flightClass->id],
            [
                'total_adult' => $fareData['AdultTotalPrice'] ?? null,
                'total_child' => $fareData['ChildTotalPrice'] ?? null,
                'total_infant' => $fareData['InfantTotalPrice'] ?? null,
                'last_updated_at' => now(),
            ]
        );
    }

    protected function saveDetailedTaxes(FlightClass $flightClass, array $taxesData): void
    {
        if (empty($taxesData) || !is_array($taxesData)) {
            return;
        }

        foreach ($taxesData as $passengerType => $taxItems) {
            $normalizedPassengerType = strtolower($passengerType);

            if (!is_array($taxItems)) {
                continue;
            }

            foreach ($taxItems as $taxItem) {
                $taxCode = null;
                $taxAmount = null;

                foreach ($taxItem as $key => $value) {
                    if (str_starts_with($key, 'Tax-')) {
                        $taxCode = str_replace('Tax-', '', $key);
                        $taxAmount = $value;
                        break;
                    }
                }

                if (!$taxCode) {
                    continue;
                }

                FlightTax::updateOrCreate(
                    [
                        'flight_class_id' => $flightClass->id,
                        'passenger_type' => $normalizedPassengerType,
                        'tax_code' => $taxCode,
                    ],
                    [
                        'tax_amount' => (float) $taxAmount,
                        'title_en' => $taxItem['title_en'] ?? null,
                        'title_fa' => $taxItem['title_fa'] ?? null,
                    ]
                );
            }
        }
    }

    protected function saveBaggage(FlightClass $flightClass, array $fareData): void
    {
        FlightBaggage::updateOrCreate(
            ['flight_class_id' => $flightClass->id],
            [
                'adult_weight' => $fareData['BaggageAllowanceWeight'] ?? null,
                'adult_pieces' => $fareData['BaggageAllowancePieces'] ?? null,
            ]
        );
    }

    protected function saveRules(FlightClass $flightClass, array $fareData): void
    {
        if (empty($fareData['CRCNRules']) || !is_array($fareData['CRCNRules'])) {
            return;
        }

        FlightRule::where('flight_class_id', $flightClass->id)->delete();

        foreach ($fareData['CRCNRules'] as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            FlightRule::create([
                'flight_class_id' => $flightClass->id,
                'refund_rules' => $rule['text'] ?? null,
                'percent' => isset($rule['percent']) ? (int) $rule['percent'] : null,
            ]);
        }
    }

    protected function getDatesForPriority(int $priority): array
    {
        $ranges = [
            1 => [0, 3],
            2 => [4, 7],
            3 => [8, 30],
            4 => [31, 120]
        ];
        
        [$start, $end] = $ranges[$priority] ?? [0, 3];
        
        $dates = [];
        for ($i = $start; $i <= $end; $i++) {
            $dates[] = now()->addDays($i);
        }

        return $dates;
    }
}