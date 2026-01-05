<?php

namespace App\Services;

use App\Models\{
    AirlineActiveRoute,
    Flight,
    FlightClass,
    FlightDetail
};
use App\Jobs\FetchFlightFareJob;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Log, Http};
use Illuminate\Http\Client\Pool;


class FlightUpdateService
{
    protected FlightProviderInterface $provider;
    protected string $iata;
    protected string $service;

    public function __construct(FlightProviderInterface $provider, string $iata, string $service = 'nira')
    {
        $this->provider = $provider;
        $this->iata = $iata;
        $this->service = $service;
    }

    /**
     * به‌روزرسانی پروازها بر اساس period
     * 
     * @param int $period روزهای آینده (3, 7, 30, 60, 90, 120)
     * @return array آمار عملیات
     */
    public function updateByPeriod(int $period): array
    {
        $stats = [
            'checked' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'routes_processed' => 0,
            'flights_found' => 0,
            'jobs_dispatched' => 0,
        ];

        $fullConfig = $this->provider->getConfig();
        
        $routes = AirlineActiveRoute::where('iata', $this->iata)
            ->where('application_interfaces_id', $fullConfig['id'] ?? null)
            ->get();

        if ($routes->isEmpty()) {
            Log::warning("No routes found for airline {$this->iata}");
            return $stats;
        }

        $dates = $this->getDatesForPeriod($period);

        $tasks = $this->prepareTasks($routes, $dates, $stats);

        $this->processBatchRequests($tasks, $stats);

        Log::info("Update completed for {$this->iata} - Period {$period}", $stats);

        return $stats;
    }


    protected function prepareTasks($routes, array $dates, array &$stats): array
    {
        $tasks = [];

        foreach ($routes as $route) {
            $stats['routes_processed']++;

            foreach ($dates as $date) {
                if (!$route->hasFlightOnDate($date)) {
                    continue;
                }

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

        return $tasks;
    }


    protected function processBatchRequests(array $tasks, array &$stats): void
    {
        $chunks = array_chunk($tasks, 5);

        foreach ($chunks as $chunk) {
            try {
                $responses = Http::timeout(60)
                    ->withoutVerifying()
                    ->pool(function (Pool $pool) use ($chunk) {
                        foreach ($chunk as $index => $task) {
                            $req = $task['request_data'];
                            $pool->as((string) $index)->get($req['url'], $req['query']);
                        }
                    });

                foreach ($responses as $index => $response) {
                    $task = $chunk[$index];

                    if ($response->successful()) {
                        $flights = $response->json()['AvailableFlights'] ?? [];

                        if (empty($flights)) {
                            continue;
                        }

                        $stats['flights_found'] += count($flights);

                        foreach ($flights as $flightData) {
                            $this->saveFlight($task['route'], $flightData, $task['date'], $stats);
                        }
                    } else {
                        $stats['errors']++;
                        Log::warning("API request failed", [
                            'route' => "{$task['route']->origin}-{$task['route']->destination}",
                            'status' => $response->status(),
                        ]);
                    }
                }

                sleep(1);

            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error("Batch processing error: " . $e->getMessage());
            }
        }
    }


    protected function saveFlight($route, array $flightData, Carbon $date, array &$stats): void
    {
        DB::beginTransaction();
        
        try {
            $departureDateTime = Carbon::parse($flightData['DepartureDateTime']);

            $flight = Flight::updateOrCreate(
                [
                    'airline_active_route_id' => $route->id,
                    'flight_number' => $flightData['FlightNo'],
                    'departure_datetime' => $departureDateTime,
                ],
                [
                    'updated_at' => now(),
                    'missing_count' => 0,
                ]
            );

            $isNewFlight = $flight->wasRecentlyCreated;

            FlightDetail::updateOrCreate(
                ['flight_id' => $flight->id],
                [
                    'arrival_datetime' => isset($flightData['ArrivalDateTime'])
                        ? Carbon::parse($flightData['ArrivalDateTime'])
                        : null,
                    'aircraft_code' => $flightData['AircraftCode'] ?? null,
                    'aircraft_type_code' => $flightData['AircraftTypeCode'] ?? null,
                    'updated_at' => now(),
                ]
            );

            $hasChanges = false;
            
            if (isset($flightData['ClassesStatus']) && is_array($flightData['ClassesStatus'])) {
                foreach ($flightData['ClassesStatus'] as $classData) {
                    $changed = $this->saveFlightClass($flight, $classData, $stats);
                    if ($changed) {
                        $hasChanges = true;
                    }
                }
            }

            DB::commit();

            $stats['checked']++;
            if ($isNewFlight || $hasChanges) {
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }

        } catch (\Exception $e) {
            DB::rollBack();
            $stats['errors']++;
            
            Log::error("Save flight error", [
                'flight_no' => $flightData['FlightNo'] ?? 'unknown',
                'route' => "{$route->origin}-{$route->destination}",
                'error' => $e->getMessage(),
            ]);
        }
    }


    protected function saveFlightClass(Flight $flight, array $classData, array &$stats): bool
    {
        $classCode = $classData['FlightClass'];
        $cap = $classData['Cap'];

        $flightClass = FlightClass::updateOrCreate(
            [
                'flight_id' => $flight->id,
                'class_code' => $classCode,
            ],
            [
                'payable_adult' => (float) ($classData['Price'] ?? 0),
                'payable_child' => null, 
                'payable_infant' => null, 
                'available_seats' => $this->provider->parseAvailableSeats($cap, $classCode),
                'status' => $this->provider->determineStatus($cap),
                'updated_at' => now(),
            ]
        );

        if ($flightClass->wasRecentlyCreated) {
            FetchFlightFareJob::dispatch($flightClass);
            $stats['jobs_dispatched']++;
            
            Log::info("Job dispatched for FlightClass", [
                'flight_class_id' => $flightClass->id,
                'flight_number' => $flight->flight_number,
                'class_code' => $classCode,
            ]);
        }

        return $flightClass->wasRecentlyCreated || $flightClass->wasChanged();
    }

    protected function getDatesForPeriod(int $period): array
    {
        $ranges = [
            3   => [0, 3],
            7   => [4, 7],
            30  => [8, 30],
            60  => [31, 60],
            90  => [61, 90],
            120 => [91, 120],
        ];

        [$start, $end] = $ranges[$period] ?? [0, 3];

        $dates = [];
        for ($i = $start; $i <= $end; $i++) {
            $dates[] = now()->addDays($i);
        }

        return $dates;
    }
}