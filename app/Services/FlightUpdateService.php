<?php

namespace App\Services;

use App\Models\AirlineActiveRoute;
use App\Models\Flight;
use App\Models\FlightBaggage;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Models\FlightFareBreakdown;
use App\Models\FlightRule;
use App\Models\FlightTax;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Jobs\FetchFlightFareJob;

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
            'checked' => 0, 'updated' => 0, 'skipped' => 0,
            'errors' => 0, 'routes_processed' => 0, 'flights_found' => 0,
        ];

        $routes = AirlineActiveRoute::where('iata', $this->iata)
            ->where('application_interfaces_id', $fullConfig['id'] ?? null)
            ->get();

        if ($routes->isEmpty()) {
            return $stats;
        }

        $dates = $this->getDatesForPriority($priority);

        $tasks = [];
        foreach ($routes as $route) {
            $stats['routes_processed']++;
            foreach ($dates as $date) {
                if ($route->hasFlightOnDate($date)) {
                    $tasks[] = [
                        'route' => $route,
                        'date' => $date,
                        'request_data' => $this->provider->prepareAvailabilityRequestData(
                            $route->origin,
                            $route->destination,
                            $date->format('Y-m-d')
                        ),
                    ];
                }
            }
        }

        $chunks = array_chunk($tasks, 5);

        foreach ($chunks as $chunk) {
            try {
                $responses = Http::timeout(60)->withoutVerifying()->pool(function (Pool $pool) use ($chunk) {
                    foreach ($chunk as $index => $task) {
                        $req = $task['request_data'];
                        $pool->as((string) $index)->get($req['url'], $req['query']);
                    }
                });

                foreach ($responses as $index => $response) {
                    $task = $chunk[$index];

                    if ($response->ok()) {
                        $flights = $response->json()['AvailableFlights'] ?? [];

                        if (empty($flights)) {
                            continue;
                        }

                        $stats['flights_found'] += count($flights);

                        foreach ($flights as $flightData) {
                            DB::beginTransaction();
                            try {
                                $updateResult = $this->saveFlightWithClasses($task['route'], $flightData, $priority, $task['date']);
                                $stats['checked']++;
                                $stats[$updateResult]++;
                                DB::commit();
                            } catch (\Exception $e) {
                                DB::rollBack();
                                $stats['errors']++;
                                Log::error('Save error: '.$e->getMessage());
                            }
                        }
                    } else {
                        $stats['errors']++;
                        Log::warning('Nira API Error: '.$response->status(), [
                            'route' => "{$task['route']->origin}-{$task['route']->destination}",
                        ]);
                    }

                }
                sleep(1);
            } catch (\Exception $e) {
                Log::error('Pool Error: '.$e->getMessage());
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
            'updated_at' => now(),
        ];

        $flightClass = FlightClass::updateOrCreate(
            [
                'flight_id' => $flight->id,
                'class_code' => $classCode,
            ],
            $classUpdateData
        );

        if ($flightClass->wasRecentlyCreated) {
            FetchFlightFareJob::dispatch($flightClass);
        }

        return $flightClass->wasRecentlyCreated || $flightClass->wasChanged();
    }

    public function getFare(FlightClass $flightClass, $route, array $classData, Carbon $date, Flight $flight)
    {
        $classCode = $classData['FlightClass'];

        $fareData = $this->provider->getFare(
            $route->origin,
            $route->destination,
            $classCode,
            $date->format('Y-m-d'),
            (string) $flight->flight_number
        );
        $classUpdateData = [
            'payable_adult' => $fareData['AdultTotalPrice'] ?? null,
            'payable_child' => $fareData['ChildTotalPrice'] ?? null,
            'payable_infant' => $fareData['InfantTotalPrice'] ?? null,
            'updated_at' => now(),
        ];
        $flightClass->update($classUpdateData);

        $hasFareData = ! empty($fareData) && is_array($fareData);
        if ($hasFareData) {
            $this->saveFareBreakdown($flightClass, $fareData);
            $this->saveDetailedTaxes($flightClass, $fareData['Taxes'] ?? []);
            $this->saveBaggage($flightClass, $fareData);
            $this->saveRules($flightClass, $fareData);
        }
    }

    protected function saveFareBreakdown(FlightClass $flightClass, array $fareData): void
    {
        FlightFareBreakdown::updateOrCreate(
            ['flight_class_id' => $flightClass->id],
            [
                'base_adult' => $fareData['AdultFare'] ?? null,
                'base_child' => $fareData['ChildFare'] ?? null,
                'base_infant' => $fareData['InfantFare'] ?? null,
                'updated_at' => now(),
            ]
        );
    }

    protected function saveDetailedTaxes(FlightClass $flightClass, array $taxesData): void
    {
        foreach ($taxesData as $passengerType => $taxes) {

            $taxValues = [
                'HL' => 0,
                'I6' => 0,
                'LP' => 0,
                'V0' => 0,
                'YQ' => 0,
            ];

            foreach ($taxes as $taxItem) {
                foreach ($taxItem as $key => $value) {

                    if (str_starts_with($key, 'Tax-')) {
                        $taxCode = str_replace('Tax-', '', $key);

                        if (array_key_exists($taxCode, $taxValues)) {
                            $taxValues[$taxCode] = (float) $value;
                        }
                    }
                }
            }

            FlightTax::updateOrCreate(
                [
                    'flight_class_id' => $flightClass->id,
                    'passenger_type' => $passengerType,
                ],
                $taxValues
            );
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
        if (empty($fareData['CRCNRules']) || ! is_array($fareData['CRCNRules'])) {
            return;
        }

        FlightRule::where('flight_class_id', $flightClass->id)->delete();

        foreach ($fareData['CRCNRules'] as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            FlightRule::create([
                'flight_class_id' => $flightClass->id,
                'rules' => $rule['text'] ?? null,
                'penalty_percentage' => isset($rule['percent']) ? (int) $rule['percent'] : null,
            ]);
        }
    }

    protected function getDatesForPriority(int $priority): array
    {
        $ranges = [
            3 => [0, 3],
            7 => [4, 7],
            30 => [8, 30],
            60 => [31, 60],
            90 => [61, 90],
            120 => [91, 120],
        ];

        [$start, $end] = $ranges[$priority] ?? [0, 3];

        $dates = [];
        for ($i = $start; $i <= $end; $i++) {
            $dates[] = now()->addDays($i);
        }

        return $dates;
    }
}
