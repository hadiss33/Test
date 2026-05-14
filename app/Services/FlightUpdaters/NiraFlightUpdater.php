<?php

namespace App\Services\FlightUpdaters;

use App\Jobs\FetchFlightFareJob;
use App\Models\Flight;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Models\FlightRangePrice;
use App\Services\FlightProviders\FlightProviderInterface;
use App\Services\Nira\NiraCapParser;
use App\Services\Nira\NiraFlightScorer;
use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NiraFlightUpdater
 *
 * پروازهایی که next_check_at <= NOW() و is_open = true هستند را
 * با AvailabilityFare چک می‌کند و فقط ۳ فیلد جدید را آپدیت می‌کند:
 *   - is_open
 *   - flight_score
 *   - next_check_at
 *
 * فیلدهای open_class_count/min_price/min_capacity حذف شده‌اند.
 * NiraCapParser و NiraFlightScorer هنوز از این مقادیر استفاده می‌کنند
 * اما فقط موقتاً در حین پردازش response - در DB ذخیره نمی‌شوند.
 */
class NiraFlightUpdater implements FlightUpdaterInterface
{
    protected FlightProviderInterface $provider;

    protected ?string $iata;

    protected string $service;

    private const BATCH_SIZE = 50;

    private const MAX_PER_URL = 2;

    private const FLIGHT_BATCH = 100;

    private const CLASS_BATCH = 200;

    protected array $allConfigs = [];

    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service = 'nira')
    {
        $this->provider = $provider;
        $this->iata = $iata;
        $this->service = $service;
        $this->allConfigs = [$provider->getConfig()];
    }

    public function withAllConfigs(array $configs): static
    {
        $this->allConfigs = $configs;

        return $this;
    }

    /**
     * سازگاری با controller قدیمی - period نادیده گرفته می‌شه
     */
    public function updateByPeriod(int $period): array
    {
        return $this->updateDueFlights();
    }

    /**
     * اصلی: پروازهایی که next_check_at رسیده را آپدیت کن
     */
    public function updateDueFlights(): array
    {
        $stats = [
            'flights_found' => 0,
            'classes_updated' => 0,
            'new_classes' => 0,
            'fare_jobs' => 0,
            'errors' => 0,
        ];

        $dueFlights = $this->getDueFlights();

        if ($dueFlights->isEmpty()) {
            return $stats;
        }

        $stats['flights_found'] = $dueFlights->count();

        $tasksByUrl = $this->buildTasksByUrl($dueFlights);

        if (empty($tasksByUrl)) {
            return $stats;
        }

        $chunks = $this->interleaveByUrl($tasksByUrl);
        $availabilityResults = $this->fetchAllAvailability($chunks, $stats);

        if (! empty($availabilityResults)) {
            $this->persist($availabilityResults, $stats);
        }

        return $stats;
    }

    // ─── STEP 1: پروازهایی که موعدشون رسیده ───────────────────────

    protected function getDueFlights(): Collection
    {
        $query = Flight::with(['route', 'route.applicationInterface'])
            ->where('is_open', true)
            ->where('departure_datetime', '>', now())
            ->where(function ($q) {
                $q->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', now());
            })
            ->whereHas('route.applicationInterface', function ($q) {
                $q->where('service', 'nira')->where('status', 1);
            });

        if ($this->iata) {
            $query->where('iata', $this->iata);
        } elseif (! empty($this->allConfigs)) {
            $interfaceIds = array_filter(array_column($this->allConfigs, 'id'));
            if (! empty($interfaceIds)) {
                $query->whereHas('route', function ($q) use ($interfaceIds) {
                    $q->whereIn('application_interfaces_id', $interfaceIds);
                });
            }
        }

        return $query
            ->orderByDesc('flight_score')  // اولویت بالا اول
            ->orderBy('next_check_at')     // NULL در MariaDB ASC خودش اول می‌آید
            ->limit(self::BATCH_SIZE)
            ->get();
    }

    // ─── STEP 2: گروه‌بندی task‌ها بر اساس host ────────────────────

    protected function buildTasksByUrl(Collection $flights): array
    {
        $tasksByUrl = [];
        $configMap = collect($this->allConfigs)->keyBy('id');

        foreach ($flights as $flight) {
            $interfaceId = $flight->route->application_interfaces_id ?? null;
            $config = $configMap->get($interfaceId);

            if (! $config) {
                // config پیدا نشد → جلوگیری از stuck شدن
                $flight->update(['next_check_at' => now()->addMinutes(30)]);

                continue;
            }

            $tasksByUrl[$config['base_url_ws1']][] = [
                'flight' => $flight,
                'route' => $flight->route,
                'config' => $config,
            ];
        }

        return $tasksByUrl;
    }

    // ─── STEP 3: Interleave برای توزیع load ────────────────────────

    protected function interleaveByUrl(array $tasksByUrl): array
    {
        $urlGroups = array_values($tasksByUrl);
        $maxLen = max(array_map('count', $urlGroups));
        $chunks = [];

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

    // ─── STEP 4: HTTP Pool calls به AvailabilityFare ────────────────

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
                            $route = $task['route'];
                            $flight = $task['flight'];

                            $pool->as((string) $i)->get(
                                $config['base_url_ws1'].'/AvailabilityFareJS.jsp',
                                [
                                    'AirLine' => $config['code'],
                                    'cbSource' => $route->origin,
                                    'cbTarget' => $route->destination,
                                    'DepartureDate' => Carbon::parse($flight->departure_datetime)->format('Y-m-d'),
                                    'cbAdultQty' => 1,
                                    'cbChildQty' => 0,
                                    'cbInfantQty' => 0,
                                    'OfficeUser' => $config['office_user'],
                                    'OfficePass' => $config['office_pass'],
                                ]
                            );
                        }
                    });

                foreach ($responses as $i => $response) {
                    $task = $chunk[(int) $i];
                    $flight = $task['flight'];

                    if (! ($response instanceof \Illuminate\Http\Client\Response) || ! $response->successful()) {
                        $stats['errors']++;
                        // پرواز stuck نمانه
                        $flight->update(['next_check_at' => now()->addMinutes(15)]);

                        continue;
                    }

                    $raw = $this->decodeNiraJson($response->body());
                    $allResults[] = [
                        'flight' => $flight,
                        'route' => $task['route'],
                        'iata' => $task['config']['code'],
                        'available_flights' => $raw['AvailableFlights'] ?? [],
                    ];
                }

            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('[NiraFlightUpdater] pool chunk failed: '.$e->getMessage());
            }

            usleep(500_000); // 500ms بین chunk‌ها
        }

        return $allResults;
    }

    // ─── STEP 5: Persist + Score Update ─────────────────────────────

    protected function persist(array $availabilityResults, array &$stats): void
    {
        $flightRows = [];
        $detailRows = [];
        $classRows = [];
        $scoreUpdates = [];
        $rangePrices = [];

        foreach ($availabilityResults as $result) {
            $dbFlight = $result['flight'];
            $route = $result['route'];
            $iata = $result['iata'];
            $apiFlights = $result['available_flights'];

            if (empty($apiFlights)) {
                // پرواز در response نبود → بستن
                $scoreUpdates[$dbFlight->id] = [
                    'is_open' => false,
                    'flight_score' => 0,
                    'next_check_at' => null,
                    'updated_at' => now()->toDateTimeString(),
                ];

                continue;
            }

            foreach ($apiFlights as $apiFlight) {
                $depDt = Carbon::parse($apiFlight['DepartureDateTime'])->format('Y-m-d H:i:s');
                $flightKey = $route->id.'|'.$apiFlight['FlightNo'].'|'.$depDt;

                if (! isset($flightRows[$flightKey])) {
                    $flightRows[$flightKey] = [
                        'airline_active_route_id' => $route->id,
                        'flight_number' => $apiFlight['FlightNo'],
                        'departure_datetime' => $depDt,
                        'iata' => $iata,
                        'missing_count' => 0,
                        'updated_at' => now()->toDateTimeString(),
                    ];

                    $detailRows[$flightKey] = [
                        'arrival_datetime' => isset($apiFlight['ArrivalDateTime'])
                            ? Carbon::parse($apiFlight['ArrivalDateTime'])->format('Y-m-d H:i:s')
                            : null,
                        'aircraft_code' => $apiFlight['AircraftCode'] ?? null,
                        'aircraft_type_code' => $apiFlight['AircraftTypeCode'] ?? null,
                        'updated_at' => now()->toDateTimeString(),
                    ];

                    $classRows[$flightKey] = [];
                }

                $classesStatus = $apiFlight['ClassesStatus'] ?? [];

                foreach ($classesStatus as $classData) {
                    $price = $classData['Price'] ?? '-';
                    $classCode = $classData['FlightClass'];

                    if (! is_numeric($price) || $price === '-') {
                        continue;
                    }

                    $parsed = NiraCapParser::parse($classData['Cap'] ?? '', $classCode);

                    $classRows[$flightKey][$classCode] = [
                        'class_code' => $classCode,
                        'available_seats' => $parsed['capacity'],
                        'status' => $parsed['is_open'] ? 'active' : 'closed',
                        'payable_adult' => (float) $price,
                        'updated_at' => now()->toDateTimeString(),
                    ];

                    $stats['classes_updated']++;

                }

                // محاسبه score (موقت - ذخیره در DB نمی‌شه)
                if ($apiFlight['FlightNo'] == $dbFlight->flight_number) {
                    $analysis = NiraCapParser::analyzeClasses($classesStatus);
                    $scoreResult = NiraFlightScorer::calculate(
                        openClassCount    : $analysis['open_class_count'],
                        minCapacity       : $analysis['min_capacity'],
                        minPrice          : $analysis['min_price'],
                        departureDateTime : Carbon::parse($depDt)
                    );

                    // فقط ۳ فیلد ذخیره می‌شه
                    $scoreUpdates[$dbFlight->id] = [
                        'is_open' => $analysis['open_class_count'] > 0,
                        'flight_score' => $scoreResult['flight_score'],
                        'next_check_at' => $scoreResult['next_check_at'],
                        'updated_at' => now()->toDateTimeString(),
                    ];

                    $rangePrices = [
                        'origin' => $route->origin,
                        'destination' => $route->destination,
                        'min' => $analysis['min_price'],
                        'max' => $analysis['max-price'],
                    ];
                }
            }
        }

        if (! empty($flightRows)) {
            // Upsert flights
            foreach (array_chunk(array_values($flightRows), self::FLIGHT_BATCH) as $batch) {
                Flight::upsert(
                    $batch,
                    ['airline_active_route_id', 'flight_number', 'departure_datetime'],
                    ['iata', 'missing_count', 'updated_at']
                );
            }

            $flightIdMap = $this->resolveFlightIds(array_keys($flightRows), null);

            // Upsert details
            $detailBulk = [];
            foreach ($flightIdMap as $fk => $flightId) {
                if (isset($detailRows[$fk])) {
                    $detailBulk[] = array_merge(['flight_id' => $flightId], $detailRows[$fk]);
                }
            }
            foreach (array_chunk($detailBulk, self::FLIGHT_BATCH) as $batch) {
                FlightDetail::upsert($batch, ['flight_id'], ['arrival_datetime', 'aircraft_code', 'aircraft_type_code', 'updated_at']);
            }

            // Upsert classes
            $existingClasses = $this->resolveExistingClasses($flightIdMap);
            $classBulk = [];
            $newClassKeys = [];

            foreach ($flightIdMap as $fk => $flightId) {
                foreach ($classRows[$fk] ?? [] as $classCode => $classData) {
                    $mapKey = $flightId.'|'.$classCode;
                    $classBulk[] = array_merge(['flight_id' => $flightId], $classData);

                    if (! isset($existingClasses[$mapKey])) {
                        $stats['new_classes']++;
                        $newClassKeys[] = $mapKey;
                    }
                }
            }

            foreach ($rangePrices as $batch) {
                $existing = FlightRangePrice::where('origin', $batch['origin'])
                    ->where('destination', $batch['destination'])
                    ->first();

                FlightRangePrice::updateOrCreate(
                    [
                        'origin' => $batch['origin'],
                        'destination' => $batch['destination'],
                    ],
                    [
                        'min' => $existing ? min($batch['min'], $existing->min) : $batch['min'],
                        'max' => $existing ? max($batch['max'], $existing->max) : $batch['max'],
                    ]
                );
            }

            foreach (array_chunk($classBulk, self::CLASS_BATCH) as $batch) {
                FlightClass::upsert(
                    $batch,
                    ['flight_id', 'class_code'],
                    ['available_seats', 'status', 'payable_adult', 'updated_at']
                );
            }

            // FetchFlightFareJob فقط برای کلاس‌های جدید
            if (! empty($newClassKeys)) {
                $flightIds = array_unique(array_map(fn ($k) => (int) explode('|', $k)[0], $newClassKeys));
                $newClasses = FlightClass::whereIn('flight_id', $flightIds)
                    ->get()
                    ->keyBy(fn ($c) => $c->flight_id.'|'.$c->class_code);

                foreach ($newClassKeys as $key) {
                    $flightClass = $newClasses->get($key);
                    if ($flightClass) {
                        FetchFlightFareJob::dispatch($flightClass)->onQueue('snailJob');
                        $stats['fare_jobs']++;
                    }
                }
            }
        } else {
            // هیچ پروازی در response نبود → همه را ببند
            foreach ($availabilityResults as $result) {
                if (! isset($scoreUpdates[$result['flight']->id])) {
                    $scoreUpdates[$result['flight']->id] = [
                        'is_open' => false,
                        'flight_score' => 0,
                        'next_check_at' => null,
                        'updated_at' => now()->toDateTimeString(),
                    ];
                }
            }
        }

        // آپدیت فقط ۳ فیلد جدید برای همه پروازها
        foreach ($scoreUpdates as $flightId => $data) {
            Flight::where('id', $flightId)->update($data);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    protected function resolveFlightIds(array $flightKeys, ?bool $key): array
    {
        if (empty($flightKeys)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($flightKeys), '?'));

        return Flight::selectRaw(
            "CONCAT(airline_active_route_id,'|',flight_number,'|',departure_datetime) as fkey, id"
        )
            ->whereRaw(
                "CONCAT(airline_active_route_id,'|',flight_number,'|',departure_datetime) IN ({$placeholders})",
                $flightKeys
            )
            ->pluck('id', 'fkey')
            ->all();
    }

    protected function resolveExistingClasses(array $flightIdMap): array
    {
        $flightIds = array_values($flightIdMap);
        if (empty($flightIds)) {
            return [];
        }

        return FlightClass::whereIn('flight_id', $flightIds)
            ->selectRaw("CONCAT(flight_id,'|',class_code) as ckey, id")
            ->pluck('id', 'ckey')
            ->all();
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
