<?php

namespace App\Services\FlightUpdaters;

use App\Models\AirlineActiveRoute;
use App\Models\Flight;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RavisFlightUpdater implements FlightUpdaterInterface
{
    use BulkUpsertHelper;

    protected FlightProviderInterface $provider;
    protected ?string $iata;
    protected string $service;

    private const BATCH_SIZE = 100;

    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service = 'ravis')
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

        $query = AirlineActiveRoute::where('application_interfaces_id', $fullConfig['id'] ?? null);
        if ($this->iata) {
            $query->where('iata', $this->iata);
        }
        $routes = $query->get();

        if ($routes->isEmpty()) {
            Log::warning('No routes found for Ravis service', ['iata' => $this->iata]);
            return $stats;
        }

        $stats['routes_processed'] = $routes->count();
        [$startDate, $endDate]     = $this->getDateRangeForPeriod($period);

        try {
            $flightsList = $this->provider->getCharterFlights($startDate, $endDate);

            if (empty($flightsList)) {
                return $stats;
            }

            $stats['flights_found'] = count($flightsList);

            if ($this->iata) {
                $flightsList = array_values(array_filter(
                    $flightsList,
                    fn($f) => ($f['AirlineCode'] ?? '') === $this->iata
                ));
            }

            // ─── فاز ۱: جمع‌آوری ───
            $collected = $this->collectAllData($flightsList, $routes);

            if (empty($collected['flights'])) {
                return $stats;
            }

            // ─── فاز ۲: Bulk save ───
            $this->bulkSaveAll($collected, $stats);

        } catch (\Exception $e) {
            $stats['errors']++;
            Log::error('Ravis updateByPeriod failed', [
                'period' => $period,
                'iata'   => $this->iata,
                'error'  => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    // ─────────────────────────────────────────────
    // فاز ۱: جمع‌آوری — بدون هیچ DB call
    // ─────────────────────────────────────────────

    protected function collectAllData(array $flightsList, $routes): array
    {
        $flightRows   = [];
        $detailRows   = [];
        $classDataMap = [];

        $routeMap = $routes->keyBy(fn($r) => $r->origin . '|' . $r->destination);

        foreach ($flightsList as $fd) {
            if (empty($fd['Reservable'])) continue;

            $origin      = $fd['IataCodSource'] ?? null;
            $destination = $fd['IataCodDestinate'] ?? null;
            if (! $origin || ! $destination) continue;

            $route = $routeMap->get($origin . '|' . $destination);
            if (! $route) continue;

            $depDt     = Carbon::parse($fd['FlightDateTime'])->format('Y-m-d H:i:s');
            $flightKey = $route->id . '|' . $fd['FlightNo'] . '|' . $depDt;

            $flightRows[$flightKey] = [
                'airline_active_route_id' => $route->id,
                'flight_number'           => $fd['FlightNo'],
                'departure_datetime'      => $depDt,
                'iata'                    => $fd['AirlineCode'],
                'missing_count'           => 0,
                'updated_at'              => now()->format('Y-m-d H:i:s'),
            ];

            $arrivalDt = $this->calculateArrivalTime(
                Carbon::parse($fd['FlightDateTime']),
                $fd['ArrivalTime'] ?? null
            );

            $detailRows[$flightKey] = [
                'arrival_datetime'   => $arrivalDt?->format('Y-m-d H:i:s'),
                'aircraft_code'      => $fd['AirPlaneName'] ?? null,
                'aircraft_type_code' => $fd['AirPlaneName'] ?? null,
                'updated_at'         => now()->format('Y-m-d H:i:s'),
            ];

            $classDataMap[$flightKey] = $fd;
        }

        return [
            'flights'   => $flightRows,
            'details'   => $detailRows,
            'classData' => $classDataMap,
        ];
    }

    // ─────────────────────────────────────────────
    // فاز ۲: Bulk Save
    // ─────────────────────────────────────────────

    protected function bulkSaveAll(array $collected, array &$stats): void
    {
        $flightKeys   = array_keys($collected['flights']);
        $flightRows   = array_values($collected['flights']);
        $detailRows   = $collected['details'];
        $classDataMap = $collected['classData'];

        $existingMap = $this->findExistingFlights($flightKeys);

        // ── BULK UPSERT FLIGHTS ──
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
        $classExtras = [];

        foreach ($flightKeys as $fk) {
            $flightId = $flightIdMap[$fk] ?? null;
            if (! $flightId) continue;

            $isNew = ! isset($existingMap[$fk]);
            $stats['checked']++;
            $isNew ? $stats['updated']++ : $stats['skipped']++;

            $detailBulk[] = array_merge(['flight_id' => $flightId], $detailRows[$fk]);

            $fd          = $classDataMap[$fk];
            $classCode   = $fd['Class'] ?? 'Y';
            $seats       = (int) ($fd['CapLast'] ?? 0);
            $adultTotal  = (float) ($fd['PriceView'] ?? 0);
            $childTotal  = (float) ($fd['PriceCHD'] ?? $adultTotal);
            $infantTotal = (float) ($fd['PriceINF'] ?? 0);

            $status = 'active';
            if ($seats <= 0)                      $status = 'full';
            if (($fd['Reservable'] ?? 1) === 0)   $status = 'closed';

            $classBulk[] = [
                'flight_id'       => $flightId,
                'class_code'      => $classCode,
                'payable_adult'   => $adultTotal,
                'payable_child'   => $childTotal,
                'payable_infant'  => $infantTotal,
                'available_seats' => $seats,
                'status'          => $status,
                'updated_at'      => now()->format('Y-m-d H:i:s'),
            ];

            $classExtras[] = [
                'flight_id'  => $flightId,
                'class_code' => $classCode,
                'fd'         => $fd,
            ];
        }

        // ── BULK UPSERT DETAILS ──
        foreach (array_chunk($detailBulk, self::BATCH_SIZE) as $batch) {
            FlightDetail::upsert(
                $batch,
                ['flight_id'],
                ['arrival_datetime', 'aircraft_code', 'aircraft_type_code', 'updated_at']
            );
        }

        // ── BULK UPSERT CLASSES ──
        foreach (array_chunk($classBulk, self::BATCH_SIZE) as $batch) {
            FlightClass::upsert(
                $batch,
                ['flight_id', 'class_code'],
                ['payable_adult', 'payable_child', 'payable_infant', 'available_seats', 'status', 'updated_at']
            );
        }

        // ── بگیر IDs کلاس‌ها ──
        $classIdMap    = $this->fetchClassIds($classExtras);
        $breakdownRows = [];
        $baggageRows   = [];

        foreach ($classExtras as $extra) {
            $ckey    = $extra['flight_id'] . '|' . $extra['class_code'];
            $classId = $classIdMap[$ckey] ?? null;
            if (! $classId) continue;

            $fd          = $extra['fd'];
            $adultTotal  = (float) ($fd['PriceView'] ?? 0);
            $childTotal  = (float) ($fd['PriceCHD'] ?? $adultTotal);
            $infantTotal = (float) ($fd['PriceINF'] ?? 0);
            $adultSrv    = (float) ($fd['SrvPriceFinal'] ?? 0);
            $childSrv    = (float) ($fd['SrvPriceFinalCHD'] ?? 0);

            $breakdownRows[] = [
                'flight_class_id' => $classId,
                'base_adult'      => $adultTotal - $adultSrv,
                'base_child'      => $childTotal - $childSrv,
                'base_infant'     => $infantTotal,
                'updated_at'      => now()->format('Y-m-d H:i:s'),
            ];

            $baggageRows[] = [
                'flight_class_id' => $classId,
                'adult_weight'    => $this->parseBaggageWeight($fd['FreeBag'] ?? '0'),
                'adult_pieces'    => 1,
                'child_weight'    => 0,
                'child_pieces'    => 0,
                'infant_weight'   => 0,
                'infant_pieces'   => 0,
            ];
        }

        // ── BULK UPSERT RELATED (از trait — بدون نیاز به unique constraint) ──
        $this->bulkUpsertBreakdown($breakdownRows);
        $this->bulkUpsertBaggage($baggageRows);
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

    protected function parseBaggageWeight(string $baggage): int
    {
        if (preg_match('/(\d+)/', $baggage, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    protected function calculateArrivalTime(Carbon $departure, ?string $arrivalTime): ?Carbon
    {
        if (! $arrivalTime) return null;

        try {
            [$hour, $minute] = explode(':', $arrivalTime);
            $arrival         = $departure->copy()->setTime((int) $hour, (int) $minute, 0);

            if ($arrival->lessThan($departure)) {
                $arrival->addDay();
            }

            return $arrival;
        } catch (\Exception $e) {
            return null;
        }
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