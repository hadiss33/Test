<?php

namespace App\Jobs;

use App\Models\ApplicationInterface;
use App\Models\Flight;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class FlightStatusSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function handle(): void
    {
        $startTime = microtime(true);

        $interfaces = ApplicationInterface::where('service', 'nira')
            ->where('status', 1)
            ->get();

        if ($interfaces->isEmpty()) {
            return;
        }

        $totalChanged = 0;

        foreach ($interfaces as $interface) {
            try {
                $totalChanged += $this->syncAirlineFlights($interface);
            } catch (\Throwable $e) {
                // Log::error("[FlightStatusSync] خطا در interface {$interface->id}: " . $e->getMessage());
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        Log::info("[FlightStatusSync] پایان | تغییر: {$totalChanged} | زمان: {$duration}s");
    }

    private function syncAirlineFlights(ApplicationInterface $interface): int
    {
        $config = $interface->data ?? [];
        $iata   = $config['iata'] ?? $config['code'] ?? null;

        if (!$iata) return 0;

        $schedule = $this->fetchScheduleFromNrscws($config);

        if (empty($schedule)) {
            Log::warning("[FlightStatusSync] NRSCWS خالی برگشت برای {$iata}");
            return 0;
        }

        // key = FlightNo|Origin|Destination|Date → 'O' یا 'C'
        $scheduleMap = [];
        foreach ($schedule as $item) {
            $date = Carbon::parse($item['DepartureDateTime'])->format('Y-m-d');
            $key  = $item['FlightNo'] . '|' . $item['Origin'] . '|' . $item['Destination'] . '|' . $date;
            $scheduleMap[$key] = $item['FlightStatus'];
        }

        $dbFlights = Flight::whereHas('route', fn($q) => $q->where('iata', $iata))
            ->with('route')
            ->where('departure_datetime', '>', now())
            ->where('departure_datetime', '<=', now()->addDays(120))
            ->get();

        if ($dbFlights->isEmpty()) return 0;

        $nowStr        = now()->toDateTimeString();
        $closedUpdates = [];
        $openUpdates   = [];

        foreach ($dbFlights as $flight) {
            $route = $flight->route;
            if (!$route) continue;

            $date   = $flight->departure_datetime->format('Y-m-d');
            $key    = $flight->flight_number . '|' . $route->origin . '|' . $route->destination . '|' . $date;
            $isOpen = ($scheduleMap[$key] ?? null) === 'O';

            if (!$isOpen && $flight->is_open) {
                // تازه بسته شد
                $closedUpdates[] = [
                    'id'            => $flight->id,
                    'is_open'       => false,
                    'flight_score'  => 0,
                    'next_check_at' => null,
                    'updated_at'    => $nowStr,
                ];
            } elseif ($isOpen && !$flight->is_open) {
                // دوباره باز شد → فوری check شود
                $openUpdates[] = [
                    'id'            => $flight->id,
                    'is_open'       => true,
                    'next_check_at' => $nowStr,
                    'updated_at'    => $nowStr,
                ];
            }
            // بدون تغییر → دست نزن
        }

        foreach (array_chunk($closedUpdates, 200) as $chunk) {
            Flight::upsert($chunk, ['id'], ['is_open', 'flight_score', 'next_check_at', 'updated_at']);
        }
        foreach (array_chunk($openUpdates, 200) as $chunk) {
            Flight::upsert($chunk, ['id'], ['is_open', 'next_check_at', 'updated_at']);
        }

        Log::info("[FlightStatusSync] {$iata}: بسته={" . count($closedUpdates) . "} | باز‌شد={" . count($openUpdates) . "}");

        return count($closedUpdates) + count($openUpdates);
    }

    private function fetchScheduleFromNrscws(array $config): array
    {
        $baseUrl = $config['base_url_ws2'] ?? $config['base_url_ws1'] ?? null;

        if (!$baseUrl) return [];

        try {
            $response = Http::timeout(30)
                ->withoutVerifying()
                ->get($baseUrl . '/FlightsScheduleJS.jsp', [
                    'AirLine'    => $config['code'],
                    'FromDate'   => now()->format('Y-m-d'),
                    'ToDate'     => now()->addDays(120)->format('Y-m-d'),
                    'OfficeUser' => $config['office_user'] ?? null,
                    'OfficePass' => $config['office_pass'] ?? null,
                ]);

            if (!$response->successful()) return [];

            $body = $response->body();
            if (!mb_check_encoding($body, 'UTF-8')) {
                $body = @iconv('CP1256', 'UTF-8//IGNORE', $body);
            }

            $data = json_decode($body, true);
            return $data['Flights'] ?? $data['Schedule'] ?? [];

        } catch (\Throwable $e) {
            Log::error('[FlightStatusSync] HTTP خطا: ' . $e->getMessage());
            return [];
        }
    }
}