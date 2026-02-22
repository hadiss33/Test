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


class NiraFlightUpdater implements FlightUpdaterInterface
{
    protected FlightProviderInterface $provider;
    protected string $iata;
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

    // ─────────────────────────────────────────────
    // TASKS
    // ─────────────────────────────────────────────

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

    // ─────────────────────────────────────────────
    // BATCH HTTP + BULK SAVE
    // ─────────────────────────────────────────────

    protected function processBatchRequests(array $tasks, array &$stats): void
    {
        $chunks = array_chunk($tasks, 5);

        foreach ($chunks as $chunk) {
            $requestAt = microtime(true);

            try {
                $responses = Http::timeout(60)
                    ->withoutVerifying()
                    ->pool(function (Pool $pool) use ($chunk) {
                        foreach ($chunk as $index => $task) {
                            $req = $task['request_data'];
                            $pool->as((string) $index)->get($req['url'], $req['query']);
                        }
                    });

                // جمع‌آوری همه داده‌ها از chunk
                $allFlightRows   = [];
                $allDetailRows   = [];
                $allClassRows    = [];
                $taskFlightMap   = []; // برای map کردن بعداً

                foreach ($responses as $index => $response) {
                    $task = $chunk[$index];

                    if (! ($response instanceof \Illuminate\Http\Client\Response) || ! $response->successful()) {
                        $stats['errors']++;
                        continue;
                    }

                    $flights = $response->json()['AvailableFlights'] ?? [];

                    if (empty($flights)) {
                        continue;
                    }

                    $stats['flights_found'] += count($flights);
                    $iata = $task['route']->applicationInterface->data['iata'] ?? $this->iata;

                    foreach ($flights as $flightData) {
                        $departureDateTime = Carbon::parse($flightData['DepartureDateTime'])->format('Y-m-d H:i:s');
                        $savedAt           = microtime(true);

                        // کلید یکتا برای این پرواز
                        $flightKey = $task['route']->id . '|' . $flightData['FlightNo'] . '|' . $departureDateTime;

                        $allFlightRows[$flightKey] = [
                            'airline_active_route_id' => $task['route']->id,
                            'flight_number'           => $flightData['FlightNo'],
                            'departure_datetime'      => $departureDateTime,
                            'iata'                    => $iata,
                            'missing_count'           => 0,
                            'updated_at'              => now()->format('Y-m-d H:i:s'),
                            'api_request_at'          => $requestAt,
                            'db_saved_at'             => $savedAt,
                        ];

                        $allDetailRows[$flightKey] = [
                            'arrival_datetime'  => isset($flightData['ArrivalDateTime'])
                                ? Carbon::parse($flightData['ArrivalDateTime'])->format('Y-m-d H:i:s')
                                : null,
                            'aircraft_code'     => $flightData['AircraftCode'] ?? null,
                            'aircraft_type_code' => $flightData['AircraftTypeCode'] ?? null,
                            'updated_at'        => now()->format('Y-m-d H:i:s'),
                        ];

                        // ذخیره class‌ها برای بعد از insert flights
                        $taskFlightMap[$flightKey] = $flightData['ClassesStatus'] ?? [];
                    }
                }

                if (empty($allFlightRows)) {
                    sleep(1);
                    continue;
                }

                // ─── BULK UPSERT FLIGHTS ───
                $this->bulkUpsertFlights(
                    array_values($allFlightRows),
                    array_keys($allFlightRows),
                    $allDetailRows,
                    $taskFlightMap,
                    $stats
                );

            } catch (\Exception $e) {
                $stats['errors']++;
            }

            sleep(1);
        }
    }

    // ─────────────────────────────────────────────
    // BULK UPSERT LOGIC
    // ─────────────────────────────────────────────

    protected function bulkUpsertFlights(
        array $flightRows,
        array $flightKeys,
        array $detailRows,
        array $taskFlightMap,
        array &$stats
    ): void {
        // پیدا کردن پروازهایی که قبلاً وجود داشتند (برای تشخیص new vs updated)
        $existingFlights = Flight::whereIn(
            DB::raw("CONCAT(airline_active_route_id, '|', flight_number, '|', departure_datetime)"),
            $flightKeys
        )->pluck('id', DB::raw("CONCAT(airline_active_route_id, '|', flight_number, '|', departure_datetime)"))->all();

        // Bulk upsert flights - یک query
        foreach (array_chunk($flightRows, self::FLIGHT_BATCH_SIZE) as $batch) {
            Flight::upsert(
                $batch,
                ['airline_active_route_id', 'flight_number', 'departure_datetime'],
                ['iata', 'missing_count', 'updated_at', 'api_request_at', 'db_saved_at']
            );
        }

        // بعد از upsert، ID‌های واقعی رو بگیر
        $flightModels = Flight::whereIn(
            DB::raw("CONCAT(airline_active_route_id, '|', flight_number, '|', departure_datetime)"),
            $flightKeys
        )->pluck('id', DB::raw("CONCAT(airline_active_route_id, '|', flight_number, '|', departure_datetime)"))->all();

        // جمع‌آوری detail و class rows
        $detailBulk = [];
        $classBulk  = [];
        $newClassIds = []; // برای dispatch jobs

        foreach ($flightKeys as $flightKey) {
            $flightId = $flightModels[$flightKey] ?? null;
            if (! $flightId) {
                continue;
            }

            $isNew = ! isset($existingFlights[$flightKey]);
            $stats['checked']++;
            if ($isNew) {
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }

            // Detail row
            $detailBulk[] = array_merge(
                ['flight_id' => $flightId],
                $detailRows[$flightKey]
            );

            // Class rows
            foreach ($taskFlightMap[$flightKey] as $classData) {
                $cap       = $classData['Cap'];
                $classCode = $classData['FlightClass'];

                $classBulk[] = [
                    'flight_id'       => $flightId,
                    'class_code'      => $classCode,
                    'payable_adult'   => (float) ($classData['Price'] ?? 0),
                    'payable_child'   => null,
                    'payable_infant'  => null,
                    'available_seats' => $this->provider->parseAvailableSeats($cap, $classCode),
                    'status'          => $this->provider->determineStatus($cap),
                    'updated_at'      => now()->format('Y-m-d H:i:s'),
                    '_flight_key'     => $flightKey, // temp برای tracking
                    '_is_new_flight'  => $isNew,
                ];
            }
        }

        // ─── BULK UPSERT DETAILS ───
        if (! empty($detailBulk)) {
            foreach (array_chunk($detailBulk, self::FLIGHT_BATCH_SIZE) as $batch) {
                FlightDetail::upsert(
                    $batch,
                    ['flight_id'],
                    ['arrival_datetime', 'aircraft_code', 'aircraft_type_code', 'updated_at']
                );
            }
        }

        // ─── BULK UPSERT CLASSES ───
        if (! empty($classBulk)) {
            $this->bulkUpsertClasses($classBulk, $stats);
        }
    }

    protected function bulkUpsertClasses(array $classBulk, array &$stats): void
    {
        // قبل از upsert، پیدا کردن کلاس‌هایی که جدید هستند (برای Job dispatch)
        $existingClassKeys = [];
        $lookupPairs = array_map(fn($r) => ['flight_id' => $r['flight_id'], 'class_code' => $r['class_code']], $classBulk);

        // چون WHERE (flight_id, class_code) IN (...) در Laravel مستقیم نداریم، این رو می‌سازیم
        if (! empty($lookupPairs)) {
            $orConditions = collect($lookupPairs)->map(
                fn($p) => "(flight_id = {$p['flight_id']} AND class_code = '{$p['class_code']}')"
            )->join(' OR ');

            $existingClassKeys = FlightClass::whereRaw($orConditions)
                ->pluck('id', DB::raw("CONCAT(flight_id, '|', class_code)"))
                ->all();
        }

        // حذف کلیدهای temp از rows قبل از upsert
        $cleanRows = array_map(function ($row) {
            unset($row['_flight_key'], $row['_is_new_flight']);
            return $row;
        }, $classBulk);

        foreach (array_chunk($cleanRows, self::CLASS_BATCH_SIZE) as $batch) {
            FlightClass::upsert(
                $batch,
                ['flight_id', 'class_code'],
                ['payable_adult', 'available_seats', 'status', 'updated_at']
            );
        }

        // بعد از upsert، Job dispatch برای کلاس‌های جدید
        $newClasses = FlightClass::whereRaw($orConditions ?? '1=0')
            ->whereNotIn('id', array_values($existingClassKeys))
            ->get();

        foreach ($newClasses as $flightClass) {
            FetchFlightFareJob::dispatch($flightClass);
            $stats['jobs_dispatched']++;
        }
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

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