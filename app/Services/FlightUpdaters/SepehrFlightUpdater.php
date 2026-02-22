<?php

namespace App\Services\FlightUpdaters;

use App\Models\AirlineActiveRoute;
use App\Models\Flight;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SepehrFlightUpdater implements FlightUpdaterInterface
{
    use BulkUpsertHelper;

    protected FlightProviderInterface $provider;
    protected ?string $iata;
    protected string $service;

    private const BATCH_SIZE = 100;

    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service = 'sepehr')
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
        $routes     = AirlineActiveRoute::where('application_interfaces_id', $fullConfig['id'] ?? null)->get();

        if ($routes->isEmpty()) {
            Log::warning('No routes found for Sepehr service');
            return $stats;
        }

        $stats['routes_processed'] = $routes->count();
        [$startDate, $endDate]     = $this->getDateRangeForPeriod($period);

        try {
            $rawData = $this->provider->getCharterFlights($startDate, $endDate);

            if (empty($rawData['CharterFlightList'])) {
                return $stats;
            }

            $flightsList            = $rawData['CharterFlightList'];
            $stats['flights_found'] = count($flightsList);

            $collected = $this->collectAllData($flightsList, $routes);

            if (empty($collected['flights'])) {
                return $stats;
            }

            $this->bulkSaveAll($collected, $stats);

        } catch (\Exception $e) {
            $stats['errors']++;
            Log::error('Sepehr updateByPeriod failed', [
                'period' => $period,
                'error'  => $e->getMessage(),
            ]);
        }

        return $stats;
    }


    protected function collectAllData(array $flightsList, $routes): array
    {
        $flightRows   = [];
        $detailRows   = [];
        $classDataMap = []; 

        // map سریع routes
        $routeMap = $routes->keyBy(fn($r) => $r->origin . '|' . $r->destination);

        foreach ($flightsList as $fd) {
            $origin      = $fd['Origin']['Code'] ?? null;
            $destination = $fd['Destination']['Code'] ?? null;

            if (! $origin || ! $destination) continue;

            $route = $routeMap->get($origin . '|' . $destination);
            if (! $route) continue;

            $depDt     = Carbon::parse($fd['DepartureDateTime'])->format('Y-m-d H:i:s');
            $flightKey = $route->id . '|' . $fd['FlightNumber'] . '|' . $depDt;

            $flightRows[$flightKey] = [
                'airline_active_route_id' => $route->id,
                'flight_number'           => $fd['FlightNumber'],
                'departure_datetime'      => $depDt,
                'iata'                    => $fd['Airline'],
                'missing_count'           => 0,
                'updated_at'              => now()->format('Y-m-d H:i:s'),
            ];

            $detailRows[$flightKey] = [
                'arrival_datetime'   => isset($fd['ArrivalDateTime'])
                    ? Carbon::parse($fd['ArrivalDateTime'])->format('Y-m-d H:i:s')
                    : null,
                'aircraft_code'      => $fd['Aircraft'] ?? null,
                'aircraft_type_code' => $fd['Aircraft'] ?? null,
                'updated_at'         => now()->format('Y-m-d H:i:s'),
            ];

            $classDataMap[$flightKey] = $fd['FlightClassList'] ?? [];
        }

        return [
            'flights'   => $flightRows,
            'details'   => $detailRows,
            'classData' => $classDataMap,
        ];
    }

    // ─────────────────────────────────────────────
    // فاز ۲: Bulk Save — کمترین تعداد query ممکن
    // ─────────────────────────────────────────────

    protected function bulkSaveAll(array $collected, array &$stats): void
    {
        $flightKeys   = array_keys($collected['flights']);
        $flightRows   = array_values($collected['flights']);
        $detailRows   = $collected['details'];
        $classDataMap = $collected['classData'];

        $existingMap = $this->findExistingFlights($flightKeys);

        foreach (array_chunk($flightRows, self::BATCH_SIZE) as $batch) {
            Flight::upsert(
                $batch,
                ['airline_active_route_id', 'flight_number', 'departure_datetime'],
                ['iata', 'missing_count', 'updated_at']
            );
        }

        $flightIdMap = $this->fetchFlightIds($flightKeys);

        $detailBulk  = [];
        $classBulk   = [];
        $classExtras = []; // برای related data بعد از upsert classes

        foreach ($flightKeys as $fk) {
            $flightId = $flightIdMap[$fk] ?? null;
            if (! $flightId) continue;

            $isNew = ! isset($existingMap[$fk]);
            $stats['checked']++;
            $isNew ? $stats['updated']++ : $stats['skipped']++;

            $detailBulk[] = array_merge(['flight_id' => $flightId], $detailRows[$fk]);

            foreach ($classDataMap[$fk] as $cd) {
                $seats = $cd['AvailableSeat'] ?? 0;
                $fare  = $cd['OnewayFare'] ?? [];

                $classBulk[] = [
                    'flight_id'       => $flightId,
                    'class_code'      => $cd['BookingCode'],
                    'payable_adult'   => (float) ($fare['Adult_Fare']['Payable'] ?? 0),
                    'payable_child'   => (float) ($fare['Child_Fare']['Payable'] ?? 0),
                    'payable_infant'  => (float) ($fare['Infant_Fare']['Payable'] ?? 0),
                    'available_seats' => $seats,
                    'status'          => $seats > 0 ? 'active' : 'full',
                    'updated_at'      => now()->format('Y-m-d H:i:s'),
                ];

                $classExtras[] = [
                    'flight_id'  => $flightId,
                    'class_code' => $cd['BookingCode'],
                    'raw'        => $cd,
                ];
            }
        }

        // ── BULK UPSERT DETAILS (یک query per chunk) ──
        foreach (array_chunk($detailBulk, self::BATCH_SIZE) as $batch) {
            FlightDetail::upsert(
                $batch,
                ['flight_id'],
                ['arrival_datetime', 'aircraft_code', 'aircraft_type_code', 'updated_at']
            );
        }

        // ── BULK UPSERT CLASSES (یک query per chunk) ──
        // flight_classes unique constraint دارد: (flight_id, class_code)
        foreach (array_chunk($classBulk, self::BATCH_SIZE) as $batch) {
            FlightClass::upsert(
                $batch,
                ['flight_id', 'class_code'],
                ['payable_adult', 'payable_child', 'payable_infant', 'available_seats', 'status', 'updated_at']
            );
        }

        // ── بگیر IDs کلاس‌ها (یک query) ──
        $classIdMap = $this->fetchClassIds($classExtras);

        // ── جمع‌آوری related rows ──
        $breakdownRows   = [];
        $baggageRows     = [];
        $taxRows         = [];
        $ruleDeleteIds   = [];
        $ruleInsertRows  = [];

        foreach ($classExtras as $extra) {
            $ckey    = $extra['flight_id'] . '|' . $extra['class_code'];
            $classId = $classIdMap[$ckey] ?? null;
            if (! $classId) continue;

            $cd   = $extra['raw'];
            $fare = $cd['OnewayFare'] ?? [];

            $breakdownRows[] = [
                'flight_class_id' => $classId,
                'base_adult'      => (float) ($fare['Adult_Fare']['BaseFare'] ?? 0),
                'base_child'      => (float) ($fare['Child_Fare']['BaseFare'] ?? 0),
                'base_infant'     => (float) ($fare['Infant_Fare']['BaseFare'] ?? 0),
                'updated_at'      => now()->format('Y-m-d H:i:s'),
            ];

            $adult  = $cd['AdultFreeBaggage'] ?? [];
            $child  = $cd['ChildFreeBaggage'] ?? [];
            $infant = $cd['InfantFreeBaggage'] ?? [];

            $baggageRows[] = [
                'flight_class_id' => $classId,
                'adult_weight'    => $adult['CheckedBaggageTotalWeight'] ?? 0,
                'adult_pieces'    => $adult['CheckedBaggageQuantity'] ?? 0,
                'child_weight'    => $child['CheckedBaggageTotalWeight'] ?? 0,
                'child_pieces'    => $child['CheckedBaggageQuantity'] ?? 0,
                'infant_weight'   => $infant['CheckedBaggageTotalWeight'] ?? 0,
                'infant_pieces'   => $infant['CheckedBaggageQuantity'] ?? 0,
            ];

            foreach (['adult' => 'Adult_Fare', 'child' => 'Child_Fare', 'infant' => 'Infant_Fare'] as $type => $fareKey) {
                $taxRows[] = [
                    'flight_class_id' => $classId,
                    'passenger_type'  => $type,
                    'YQ'              => (float) ($fare[$fareKey]['Tax'] ?? 0),
                    'HL'              => null,
                    'I6'              => null,
                    'LP'              => null,
                    'V0'              => null,
                ];
            }

            $ruleDeleteIds[] = $classId;

            $persianPolicy = collect($cd['CancelationPolicyList'] ?? [])->firstWhere('Culture', 'fa-IR');
            if ($persianPolicy && ! empty($persianPolicy['Text'])) {
                foreach (explode("\r\n", $persianPolicy['Text']) as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    $pct = null;
                    if (preg_match('/(\d+)\s*درصد/', $line, $m)) {
                        $pct = (int) $m[1];
                    }

                    $ruleInsertRows[] = [
                        'flight_class_id'    => $classId,
                        'rules'              => $line,
                        'penalty_percentage' => $pct,
                    ];
                }
            }
        }

        // ── BULK UPSERT related (از BulkUpsertHelper) ──
        $this->bulkUpsertBreakdown($breakdownRows);  // بدون نیاز به unique constraint
        $this->bulkUpsertBaggage($baggageRows);       // بدون نیاز به unique constraint
        $this->bulkUpsertTax($taxRows);               // بدون نیاز به unique constraint
        $this->bulkReplaceRules($ruleDeleteIds, $ruleInsertRows); // یک DELETE + bulk INSERT
    }

    // ─────────────────────────────────────────────
    // HELPER QUERIES
    // ─────────────────────────────────────────────

    protected function findExistingFlights(array $flightKeys): array
    {
        if (empty($flightKeys)) return [];

        return Flight::selectRaw("CONCAT(airline_active_route_id, '|', flight_number, '|', departure_datetime) as fkey, id")
            ->whereRaw(
                "CONCAT(airline_active_route_id, '|', flight_number, '|', departure_datetime) IN (" .
                implode(',', array_fill(0, count($flightKeys), '?')) . ')',
                $flightKeys
            )
            ->pluck('id', 'fkey')
            ->all();
    }

    protected function fetchFlightIds(array $flightKeys): array
    {
        return $this->findExistingFlights($flightKeys);
    }

    protected function fetchClassIds(array $classExtras): array
    {
        if (empty($classExtras)) return [];

        $pairs = collect($classExtras)
            ->map(fn($e) => "(flight_id = {$e['flight_id']} AND class_code = '" . addslashes($e['class_code']) . "')")
            ->join(' OR ');

        return FlightClass::selectRaw("CONCAT(flight_id, '|', class_code) as ckey, id")
            ->whereRaw($pairs)
            ->pluck('id', 'ckey')
            ->all();
    }

    protected function getDateRangeForPeriod(int $period): array
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

        return [
            now()->addDays($start)->format('Y-m-d'),
            now()->addDays($end)->format('Y-m-d'),
        ];
    }
}