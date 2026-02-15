<?php

namespace App\Services\FlightUpdaters;

use App\Jobs\FetchFlightFareJob;
use App\Models\AirlineActiveRoute;
use App\Models\Flight;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nira Flight Updater
 *
 * Uses 2-step process:
 * 1. getAvailabilityFare - Get flights with basic info + classes
 * 2. getFare (via Job) - Get detailed fare breakdown, baggage, taxes, rules
 */
class NiraFlightUpdater implements FlightUpdaterInterface
{
    protected FlightProviderInterface $provider;

    protected string $iata;

    protected string $service;

    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service = 'nira')
    {
        $this->provider = $provider;
        $this->iata = $iata;
        $this->service = $service;
    }

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
        $routes = AirlineActiveRoute::where('application_interfaces_id', $fullConfig['id'] ?? null)
          ->with('applicationInterface') 
         ->get(); 

        if ($routes->isEmpty()) {
            Log::warning("No routes found for airline {$this->iata}");

            return $stats;
        }

        $dates = $this->getDatesForPeriod($period);
        $tasks = $this->prepareTasks($routes, $dates, $stats);
        $this->processBatchRequests($tasks, $stats);

        Log::info("Nira update completed for {$this->iata} - Period {$period}", $stats);

        return $stats;
    }

    protected function prepareTasks($routes, array $dates, array &$stats): array
    {
        $tasks = [];

        foreach ($routes as $route) {
            $stats['routes_processed']++;

            foreach ($dates as $date) {
                if (! $route->hasFlightOnDate($date)) {
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

            $chunkStartTime = microtime(true);
            foreach ($chunk as &$taskRef) {
                $taskRef['start_microtime'] = $chunkStartTime;
            }
            unset($taskRef);
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

                    $startTime = $task['start_microtime'] ?? microtime(true);

                    if ($response instanceof \Illuminate\Http\Client\Response) {
                        if ($response->successful()) {
                            $flights = $response->json()['AvailableFlights'] ?? [];

                            if (empty($flights)) {
                                continue;
                            }


                            $stats['flights_found'] += count($flights);

                            foreach ($flights as $flightData) {
                                $this->saveFlight($task['route'], $flightData, $task['date'], $stats, $startTime);
                            }
                        } else {
                            $stats['errors']++;
                            Log::warning('Nira API request failed', [
                                'route' => "{$task['route']->origin}-{$task['route']->destination}",
                                'status' => $response->status(),
                            ]);
                        }
                    } else {
                        $stats['errors']++;
                        Log::error('Nira API connection error', [
                            'route' => "{$task['route']->origin}-{$task['route']->destination}",
                            'error' => $response instanceof \Throwable ? $response->getMessage() : 'Unknown',
                        ]);
                    }
                }

                sleep(1);

            } catch (\Exception $e) {
                Log::error('Batch processing fatal error: '.$e->getMessage());
            }
        }
    }

    protected function saveFlight($route, array $flightData, Carbon $date, array &$stats, $startTime): void
    {
        DB::beginTransaction();
        $iata = $route->applicationInterface->data['iata']?? $this->iata;
        try {
            $departureDateTime = Carbon::parse($flightData['DepartureDateTime']);
            $endTime = microtime(true);
            $flight = Flight::updateOrCreate(
                [
                    'airline_active_route_id' => $route->id,
                    'flight_number' => $flightData['FlightNo'],
                    'departure_datetime' => $departureDateTime,
                    'iata' => $iata,
                ],
                [
                    'updated_at' => now(),
                    'missing_count' => 0,
                    'api_request_at' => $startTime,
                    'db_saved_at' => $endTime,
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

            Log::error('Nira save flight error', [
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

            Log::info('Nira Job dispatched for FlightClass', [
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
            3 => [0, 3],
            7 => [4, 7],
            30 => [8, 30],
            60 => [31, 60],
            90 => [61, 90],
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
