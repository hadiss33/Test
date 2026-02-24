<?php

namespace App\Services\FlightUpdaters;

use App\Models\AirlineActiveRoute;
use App\Models\Flight;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Jobs\FetchFlightFareJob;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NiraFlightUpdater implements FlightUpdaterInterface
{
    protected FlightProviderInterface $provider;
    protected ?string $iata;
    protected string $service;


    private const MAX_PER_URL = 2;

    private const FLIGHT_BATCH = 100;
    private const CLASS_BATCH  = 200;

    protected array $allConfigs = [];

    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service = 'nira')
    {
        $this->provider   = $provider;
        $this->iata       = $iata;
        $this->service    = $service;
        $this->allConfigs = [$provider->getConfig()];
    }


    public function withAllConfigs(array $configs): static
    {
        $this->allConfigs = $configs;
        return $this;
    }

    public function updateByPeriod(int $period): array
    {
        $stats = [
            'routes_processed' => 0,
            'flights_found'    => 0,
            'classes_updated'  => 0,
            'new_classes'      => 0,
            'fare_jobs'        => 0,
            'errors'           => 0,
        ];

        $dates = $this->getDatesForPeriod($period);

        $tasksByUrl = $this->buildTasksGroupedByUrl($dates, $stats);

        if (empty($tasksByUrl)) {
            return $stats;
        }

        $chunks = $this->interleaveByUrl($tasksByUrl);
        $availabilityResults = $this->fetchAllAvailability($chunks, $stats);

        if (empty($availabilityResults)) {
            return $stats;
        }

        $this->persist($availabilityResults, $stats);

        return $stats;
    }


    protected function buildTasksGroupedByUrl(array $dates, array &$stats): array
    {
        $tasksByUrl = [];

        foreach ($this->allConfigs as $config) {
            $routes = AirlineActiveRoute::where('application_interfaces_id', $config['id'] ?? null)
                ->with('applicationInterface')
                ->get();

            if ($routes->isEmpty()) {
                continue;
            }

            $baseUrl = $config['base_url_ws1'];

            foreach ($routes as $route) {
                $stats['routes_processed']++;

                foreach ($dates as $date) {
                    if (! $route->hasFlightOnDate($date)) {
                        continue;
                    }

                    $tasksByUrl[$baseUrl][] = [
                        'route'  => $route,
                        'date'   => $date,
                        'config' => $config,
                    ];
                }
            }
        }

        return $tasksByUrl;
    }


    protected function interleaveByUrl(array $tasksByUrl): array
    {
        $urlGroups = array_values($tasksByUrl);
        $maxLen    = max(array_map('count', $urlGroups));
        $chunks    = [];

        for ($i = 0; $i < $maxLen; $i += self::MAX_PER_URL) {
            $chunk = [];

            foreach ($urlGroups as $group) {
                for ($j = 0; $j < self::MAX_PER_URL; $j++) {
                    if (isset($group[$i + $j])) {
                        $chunk[] = $group[$i + $j];
                    }
                }
            }

            if (! empty($chunk)) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }


    protected function fetchAllAvailability(array $chunks, array &$stats): array
    {
        $allResults = [];

        foreach ($chunks as $chunk) {
            try {
                $responses = Http::timeout(60)
                    ->withoutVerifying()
                    ->pool(function (Pool $pool) use ($chunk) {
                        foreach ($chunk as $i => $task) {
                            $config = $task['config'];

                            $pool->as((string) $i)->get(
                                $config['base_url_ws1'] . '/AvailabilityFareJS.jsp',
                                [
                                    'AirLine'       => $config['code'],
                                    'cbSource'      => $task['route']->origin,
                                    'cbTarget'      => $task['route']->destination,
                                    'DepartureDate' => $task['date']->format('Y-m-d'),
                                    'cbAdultQty'    => 1,
                                    'cbChildQty'    => 0,
                                    'cbInfantQty'   => 0,
                                    'OfficeUser'    => $config['office_user'],
                                    'OfficePass'    => $config['office_pass'],
                                ]
                            );
                        }
                    });

                foreach ($responses as $i => $response) {
                    $task = $chunk[(int) $i];

                    if (! ($response instanceof \Illuminate\Http\Client\Response) || ! $response->successful()) {
                        $stats['errors']++;
                        continue;
                    }

                    $raw     = $this->decodeNiraJson($response->body());
                    $flights = $raw['AvailableFlights'] ?? [];

                    if (empty($flights)) {
                        continue;
                    }

                    $taskKey = $task['route']->id . '|' . $task['date']->format('Y-m-d');

                    $stats['flights_found'] += count($flights);

                    $allResults[$taskKey] = [
                        'route'   => $task['route'],
                        'date'    => $task['date'],
                        'iata'    => $task['config']['code'],
                        'config'  => $task['config'],
                        'flights' => $flights,
                    ];
                }

            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('NiraFlightUpdater: pool chunk failed', ['error' => $e->getMessage()]);
            }

            usleep(500_000); // 500ms
        }

        return $allResults;
    }


    protected function persist(array $availabilityResults, array &$stats): void
    {
        $flightRows = [];
        $detailRows = [];
        $classRows  = [];

        foreach ($availabilityResults as $result) {
            $route = $result['route'];
            $iata  = $result['iata'];

            foreach ($result['flights'] as $flight) {
                $depDt     = Carbon::parse($flight['DepartureDateTime'])->format('Y-m-d H:i:s');
                $flightKey = $route->id . '|' . $flight['FlightNo'] . '|' . $depDt;

                if (! isset($flightRows[$flightKey])) {
                    $flightRows[$flightKey] = [
                        'airline_active_route_id' => $route->id,
                        'flight_number'           => $flight['FlightNo'],
                        'departure_datetime'      => $depDt,
                        'iata'                    => $iata,
                        'missing_count'           => 0,
                        'updated_at'              => now()->format('Y-m-d H:i:s'),
                    ];

                    $detailRows[$flightKey] = [
                        'arrival_datetime'   => isset($flight['ArrivalDateTime'])
                            ? Carbon::parse($flight['ArrivalDateTime'])->format('Y-m-d H:i:s')
                            : null,
                        'aircraft_code'      => $flight['AircraftCode']     ?? null,
                        'aircraft_type_code' => $flight['AircraftTypeCode'] ?? null,
                        'updated_at'         => now()->format('Y-m-d H:i:s'),
                    ];

                    $classRows[$flightKey] = [];
                }

                foreach ($flight['ClassesStatus'] as $classData) {
                    $price     = $classData['Price'] ?? '-';
                    $classCode = $classData['FlightClass'];

                    if (! is_numeric($price) || $price === '-') {
                        continue;
                    }

                    $classRows[$flightKey][$classCode] = [
                        'class_code'      => $classCode,
                        'available_seats' => $this->provider->parseAvailableSeats($classData['Cap'], $classCode),
                        'status'          => $this->provider->determineStatus($classData['Cap']),
                        'payable_adult'   => (float) $price,
                        'updated_at'      => now()->format('Y-m-d H:i:s'),
                    ];

                    $stats['classes_updated']++;
                }
            }
        }

        if (empty($flightRows)) {
            return;
        }

        foreach (array_chunk(array_values($flightRows), self::FLIGHT_BATCH) as $batch) {
            Flight::upsert(
                $batch,
                ['airline_active_route_id', 'flight_number', 'departure_datetime'],
                ['iata', 'missing_count', 'updated_at']
            );
        }

        $flightIdMap = $this->resolveFlightIds(array_keys($flightRows));

        $detailBulk = [];
        foreach ($flightIdMap as $fk => $flightId) {
            if (isset($detailRows[$fk])) {
                $detailBulk[] = array_merge(['flight_id' => $flightId], $detailRows[$fk]);
            }
        }
        foreach (array_chunk($detailBulk, self::FLIGHT_BATCH) as $batch) {
            FlightDetail::upsert($batch, ['flight_id'], ['arrival_datetime', 'aircraft_code', 'aircraft_type_code', 'updated_at']);
        }

        $existingClasses = $this->resolveExistingClasses($flightIdMap);
        $classBulk       = [];
        $newClassKeys    = [];

        foreach ($flightIdMap as $fk => $flightId) {
            foreach ($classRows[$fk] ?? [] as $classCode => $classData) {
                $mapKey = $flightId . '|' . $classCode;
                $isNew  = ! isset($existingClasses[$mapKey]);

                $classBulk[] = array_merge(['flight_id' => $flightId], $classData);

                if ($isNew) {
                    $stats['new_classes']++;
                    $newClassKeys[] = $mapKey;
                }
            }
        }

        foreach (array_chunk($classBulk, self::CLASS_BATCH) as $batch) {
            FlightClass::upsert(
                $batch,
                ['flight_id', 'class_code'],
                ['available_seats', 'status', 'payable_adult', 'updated_at']
                // payable_child/infant اینجا نیستن — FetchFlightFareJob اونا رو پر می‌کنه
            );
        }


        if (! empty($newClassKeys)) {
            $flightIds  = array_unique(array_map(fn($k) => (int) explode('|', $k)[0], $newClassKeys));
            $newClasses = FlightClass::whereIn('flight_id', $flightIds)->get()
                ->keyBy(fn($c) => $c->flight_id . '|' . $c->class_code);

            foreach ($newClassKeys as $key) {
                $flightClass = $newClasses->get($key);
                if ($flightClass) {
                    FetchFlightFareJob::dispatch($flightClass)->onQueue('snailJob');
                    $stats['fare_jobs']++;
                }
            }
        }
    }

    protected function resolveFlightIds(array $flightKeys): array
    {
        if (empty($flightKeys)) return [];

        return Flight::selectRaw(
            "CONCAT(airline_active_route_id,'|',flight_number,'|',departure_datetime) as fkey, id"
        )
        ->whereRaw(
            "CONCAT(airline_active_route_id,'|',flight_number,'|',departure_datetime) IN ("
            . implode(',', array_fill(0, count($flightKeys), '?')) . ')',
            $flightKeys
        )
        ->pluck('id', 'fkey')
        ->all();
    }

    protected function resolveExistingClasses(array $flightIdMap): array
    {
        $flightIds = array_values($flightIdMap);
        if (empty($flightIds)) return [];

        return FlightClass::whereIn('flight_id', $flightIds)
            ->selectRaw("CONCAT(flight_id,'|',class_code) as ckey, id")
            ->pluck('id', 'ckey')
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

    protected function decodeNiraJson(string $rawBody): ?array
    {
        if (! mb_check_encoding($rawBody, 'UTF-8')) {
            $rawBody = @iconv('CP1256', 'UTF-8//IGNORE', $rawBody);
        }
        $rawBody = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $rawBody ?? '');

        return json_decode($rawBody, true);
    }
}