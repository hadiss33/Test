<?php

namespace App\Jobs;

use App\Models\AirlineActiveRoute;
use App\Models\ApplicationInterface;
use App\Models\Flight;
use App\Services\Nira\NiraCapParser;
use App\Services\Nira\NiraFlightScorer;
use App\Services\FlightProviders\NiraProvider;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * FlightStatusSyncJob
 *
 * مسئولیت: هر ۱ ساعت یکبار از NRSCWS وضعیت پروازها رو sync کن
 * - برای هر airline یک call به NRSCWS (بازه ۰-۱۲۰ روز)
 * - is_open هر پرواز رو آپدیت کن
 * - flight_score و next_check_at رو recalculate کن
 * - فقط برای سرویس نیرا، بقیه سرویس‌ها دست‌نخورده می‌مونن
 */
class FlightStatusSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function handle(NiraProvider $provider): void
    {
        $startTime = microtime(true);

        Log::info('[FlightStatusSync] شروع sync وضعیت پروازهای نیرا');

        // دریافت تمام interface های نیرا
        $interfaces = ApplicationInterface::where('service', 'nira')
            ->where('status', 1)
            ->get();

        if ($interfaces->isEmpty()) {
            Log::warning('[FlightStatusSync] هیچ interface نیرایی فعال نیست');
            return;
        }

        $from = now()->format('Y-m-d');
        $to   = now()->addDays(120)->format('Y-m-d');

        $totalUpdated = 0;

        foreach ($interfaces as $interface) {
            try {
                $updated = $this->syncAirlineFlights($provider, $interface, $from, $to);
                $totalUpdated += $updated;
            } catch (\Throwable $e) {
                Log::error("[FlightStatusSync] خطا در airline {$interface->object}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        Log::info("[FlightStatusSync] پایان. آپدیت شده: {$totalUpdated} پرواز | زمان: {$duration}s");
    }

    private function syncAirlineFlights(
        NiraProvider        $provider,
        ApplicationInterface $interface,
        string              $from,
        string              $to
    ): int {
        $iata = $interface->data['iata'] ?? null;
        if (!$iata) {
            return 0;
        }

        // یک call به NRSCWS برای همه پروازهای این airline
        $schedule = $provider->getFlightsSchedule($interface, $from, $to);

        if (empty($schedule)) {
            Log::warning("[FlightStatusSync] NRSCWS خالی برگشت برای airline {$iata}");
            return 0;
        }

        // ساخت map از schedule برای جستجوی سریع
        // key = FlightNo|Origin|Destination|Date
        $scheduleMap = [];
        foreach ($schedule as $flight) {
            $date = Carbon::parse($flight['DepartureDateTime'])->format('Y-m-d');
            $key  = $flight['FlightNo'] . '|' . $flight['Origin'] . '|' . $flight['Destination'] . '|' . $date;
            $scheduleMap[$key] = $flight['FlightStatus']; // O یا C
        }

        // دریافت پروازهای آینده این airline از DB
        $dbFlights = Flight::whereHas('airlineActiveRoute', function ($q) use ($iata) {
                $q->where('iata', $iata);
            })
            ->where('departure_datetime', '>', now())
            ->where('departure_datetime', '<=', now()->addDays(120))
            ->get();

        if ($dbFlights->isEmpty()) {
            return 0;
        }

        $updates = [];

        foreach ($dbFlights as $dbFlight) {
            $date = Carbon::parse($dbFlight->departure_datetime)->format('Y-m-d');
            $key  = $dbFlight->flight_number . '|' . $dbFlight->origin . '|' . $dbFlight->destination . '|' . $date;

            $statusInSchedule = $scheduleMap[$key] ?? null;

            // اگه در schedule نیست یا بسته‌ست
            $isOpen = $statusInSchedule === 'O';

            $scoreResult = NiraFlightScorer::calculate(
                openClassCount    : $isOpen ? max(1, $dbFlight->open_class_count) : 0,
                minCapacity       : $dbFlight->min_capacity ?? 0,
                minPrice          : $dbFlight->min_price ?? 0,
                departureDateTime : Carbon::parse($dbFlight->departure_datetime)
            );

            $updates[] = [
                'id'               => $dbFlight->id,
                'is_open'          => $isOpen,
                // اگه بسته شد open_class_count رو صفر کن
                'open_class_count' => $isOpen ? $dbFlight->open_class_count : 0,
                'flight_score'     => $scoreResult['flight_score'],
                'next_check_at'    => $scoreResult['next_check_at'],
                'status_checked_at'=> now(),
            ];
        }

        if (!empty($updates)) {
            // Bulk update به صورت chunk
            foreach (array_chunk($updates, 200) as $chunk) {
                Flight::upsert(
                    $chunk,
                    ['id'],
                    ['is_open', 'open_class_count', 'flight_score', 'next_check_at', 'status_checked_at']
                );
            }
        }

        $openCount   = collect($updates)->where('is_open', true)->count();
        $closedCount = collect($updates)->where('is_open', false)->count();

        Log::info("[FlightStatusSync] airline {$iata}: باز={$openCount} | بسته={$closedCount}");

        return count($updates);
    }
}