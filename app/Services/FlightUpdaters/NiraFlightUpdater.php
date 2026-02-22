<?php

namespace App\Services\FlightUpdaters;

use App\Jobs\FetchFlightFareJob;
use App\Models\AirlineActiveRoute;
use App\Models\Flight;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NiraFlightUpdater implements FlightUpdaterInterface
{
    protected FlightProviderInterface $provider;
    protected ?string $iata;
    protected string $service;

    private const FLIGHT_BATCH_SIZE = 100;
    private const CLASS_BATCH_SIZE  = 200;

    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service = 'nira')
    {
        $this->provider = $provider;
        $this->iata     = $iata;
        $this->service  = $service;
    }

    public function updateByPeriod(int $period): array
    {
        $stats = [
            'checked'          => 0,
            'updated'          => 0,
            'skipped'          => 0,
            'errors'           => 0,
            'routes_processed' => 0,
            'flights_found'    => 0,
            'jobs_dispatched'  => 0,
        ];

        $fullConfig = $this->provider->getConfig();

        $routes = AirlineActiveRoute::where('application_interfaces_id', $fullConfig['id'] ?? null)
            ->with('applicationInterface')
            ->get();

        if ($routes->isEmpty()) {
            return $stats;
        }

        $dates = $this->getDatesForPeriod($period);
        $tasks = $this->prepareTasks($routes, $dates, $stats);
        $this->processBatchRequests($tasks, $stats);

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
                    'route'        => $route,
                    'date'         => $date,
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
        foreach (array_chunk($tasks, 5) as $chunk) {
            try {
                $responses = Http::timeout(60)
                    ->withoutVerifying()
                    ->pool(function (Pool $pool) use ($chunk) {
                        foreach ($chunk as $index => $task) {
                            $req = $task['request_data'];
                            $pool->as((string) $index)->get($req['url'], $req['query']);
                        }
                    });

                $allFlightRows = [];
                $allDetailRows = [];
                $taskFlightMap = [];

                foreach ($responses as $index => $response) {
                    $task = $chunk[$index];

                    if (! ($response instanceof \Illuminate\Http\Client\Response) || ! $response->successful()) {
                        $stats['errors']++;
                        continue;
                    }

                    $flights = $response->json()['AvailableFlights'] ?? [];
                    if (empty($flights)) continue;

                    $stats['flights_found'] += count($flights);
                    $iata = $task['route']->applicationInterface->data['iata'] ?? $this->iata;

                    foreach ($flights as $fd) {
                        $depDt     = Carbon::parse($fd['DepartureDateTime'])->format('Y-m-d H:i:s');
                        $flightKey = $task['route']->id . '|' . $fd['FlightNo'] . '|' . $depDt;

                        $allFlightRows[$flightKey] = [
                            'airline_active_route_id' => $task['route']->id,
                            'flight_number'           => $fd['FlightNo'],
                            'departure_datetime'      => $depDt,
                            'iata'                    => $iata,
                            'missing_count'           => 0,
                            'updated_at'              => now()->format('Y-m-d H:i:s'),
                        ];

                        $allDetailRows[$flightKey] = [
                            'arrival_datetime'   => isset($fd['ArrivalDateTime'])
                                ? Carbon::parse($fd['ArrivalDateTime'])->format('Y-m-d H:i:s')
                                : null,
                            'aircraft_code'      => $fd['AircraftCode'] ?? null,
                            'aircraft_type_code' => $fd['AircraftTypeCode'] ?? null,
                            'updated_at'         => now()->format('Y-m-d H:i:s'),
                        ];

                        $taskFlightMap[$flightKey] = $fd['ClassesStatus'] ?? [];
                    }
                }

                if (empty($allFlightRows)) {
                    sleep(1);
                    continue;
                }

                $this->processFlights($allFlightRows, $allDetailRows, $taskFlightMap, $stats);

            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('NiraFlightUpdater chunk failed', ['error' => $e->getMessage()]);
            }

            sleep(1);
        }
    }

    protected function processFlights(
        array $allFlightRows,
        array $allDetailRows,
        array $taskFlightMap,
        array &$stats
    ): void {
        $flightKeys = array_keys($allFlightRows);

        $existingFlightIds = $this->findExistingFlights($flightKeys);

        $newFlightKeys      = [];
        $existingFlightKeys = []; // [flightKey => flight_id]

        foreach ($flightKeys as $key) {
            if (isset($existingFlightIds[$key])) {
                $existingFlightKeys[$key] = $existingFlightIds[$key];
            } else {
                $newFlightKeys[] = $key;
            }
        }

        if (! empty($newFlightKeys)) {
            $this->handleNewFlights($newFlightKeys, $allFlightRows, $allDetailRows, $taskFlightMap, $stats);
        }

        if (! empty($existingFlightKeys)) {
            $this->handleExistingFlights($existingFlightKeys, $allDetailRows, $taskFlightMap, $stats);
        }
    }

    protected function handleNewFlights(
        array $newFlightKeys,
        array $allFlightRows,
        array $allDetailRows,
        array $taskFlightMap,
        array &$stats
    ): void {
        $flightRows = array_map(fn($k) => $allFlightRows[$k], $newFlightKeys);
        foreach (array_chunk($flightRows, self::FLIGHT_BATCH_SIZE) as $batch) {
            Flight::insert($batch);
        }

        $newFlightIds = $this->findExistingFlights($newFlightKeys);

        $detailRows = [];
        $classRows  = [];

        foreach ($newFlightKeys as $key) {
            $flightId = $newFlightIds[$key] ?? null;
            if (! $flightId) continue;

            $stats['checked']++;
            $stats['updated']++;

            $detailRows[] = array_merge(['flight_id' => $flightId], $allDetailRows[$key]);

            foreach ($taskFlightMap[$key] as $classData) {
                $cap       = $classData['Cap'];
                $classCode = $classData['FlightClass'];

                $classRows[] = [
                    'flight_id'       => $flightId,
                    'class_code'      => $classCode,
                    'payable_adult'   => (float) ($classData['Price'] ?? 0),
                    'payable_child'   => null,  
                    'payable_infant'  => null,  
                    'available_seats' => $this->provider->parseAvailableSeats($cap, $classCode),
                    'status'          => $this->provider->determineStatus($cap),
                    'updated_at'      => now()->format('Y-m-d H:i:s'),
                ];
            }
        }

        foreach (array_chunk($detailRows, self::FLIGHT_BATCH_SIZE) as $batch) {
            FlightDetail::insert($batch);
        }

        foreach (array_chunk($classRows, self::CLASS_BATCH_SIZE) as $batch) {
            FlightClass::insert($batch);
        }

        if (! empty($newFlightIds)) {
            $newClasses = FlightClass::whereIn('flight_id', array_values($newFlightIds))->get();

            foreach ($newClasses as $flightClass) {
                FetchFlightFareJob::dispatch($flightClass);
                $stats['jobs_dispatched']++;
            }
        }
    }

    protected function handleExistingFlights(
        array $existingFlightKeys, // [flightKey => flight_id]
        array $allDetailRows,
        array $taskFlightMap,
        array &$stats
    ): void {
        $flightIds = array_values($existingFlightKeys);
        $now       = now()->format('Y-m-d H:i:s');

        $idList = implode(',', array_map('intval', $flightIds));
        DB::statement(
            "UPDATE flights SET missing_count = 0, updated_at = ? WHERE id IN ({$idList})",
            [$now]
        );

        $detailRows = [];
        $classRows  = [];
        $classCombosToProcess = [];

        foreach ($existingFlightKeys as $key => $flightId) {
            $stats['checked']++;
            $stats['skipped']++;

            $detailRows[] = array_merge(['flight_id' => $flightId], $allDetailRows[$key]);

            foreach ($taskFlightMap[$key] as $classData) {
                $cap       = $classData['Cap'];
                $classCode = $classData['FlightClass'];

                $classRows[] = [
                    'flight_id'       => $flightId,
                    'class_code'      => $classCode,
                    'payable_adult'   => (float) ($classData['Price'] ?? 0),
                    'available_seats' => $this->provider->parseAvailableSeats($cap, $classCode),
                    'status'          => $this->provider->determineStatus($cap),
                    'updated_at'      => $now,
                ];

                $classCombosToProcess[] = $flightId . '|' . $classCode;
            }
        }


        $existingClasses = FlightClass::whereIn('flight_id', $flightIds)
            ->selectRaw("CONCAT(flight_id, '|', class_code) as combo")
            ->pluck('combo', 'combo')
            ->toArray();

        $newClassCombos = [];
        $flightIdsWithNewClasses = [];

        foreach ($classCombosToProcess as $combo) {
            if (!isset($existingClasses[$combo])) {
                $newClassCombos[] = $combo;
                $flightIdsWithNewClasses[] = explode('|', $combo)[0];
            }
        }
        $flightIdsWithNewClasses = array_unique($flightIdsWithNewClasses);

        foreach (array_chunk($detailRows, self::FLIGHT_BATCH_SIZE) as $batch) {
            FlightDetail::upsert(
                $batch,
                ['flight_id'],
                ['arrival_datetime', 'aircraft_code', 'aircraft_type_code', 'updated_at']
            );
        }

        foreach (array_chunk($classRows, self::CLASS_BATCH_SIZE) as $batch) {
            FlightClass::upsert(
                $batch,
                ['flight_id', 'class_code'],
                ['payable_adult', 'available_seats', 'status', 'updated_at']
            );
        }

        if (!empty($newClassCombos)) {
            $fetchedNewClasses = FlightClass::whereIn('flight_id', $flightIdsWithNewClasses)->get();

            foreach ($fetchedNewClasses as $cls) {
                $combo = $cls->flight_id . '|' . $cls->class_code;
                
                if (in_array($combo, $newClassCombos)) {
                    FetchFlightFareJob::dispatch($cls);
                    $stats['jobs_dispatched']++;
                }
            }
        }
    }


    protected function findExistingFlights(array $flightKeys): array
    {
        if (empty($flightKeys)) return [];

        return Flight::selectRaw(
            "CONCAT(airline_active_route_id, '|', flight_number, '|', departure_datetime) as fkey, id"
        )
        ->whereRaw(
            "CONCAT(airline_active_route_id, '|', flight_number, '|', departure_datetime) IN (" .
            implode(',', array_fill(0, count($flightKeys), '?')) . ')',
            $flightKeys
        )
        ->pluck('id', 'fkey')
        ->all();
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