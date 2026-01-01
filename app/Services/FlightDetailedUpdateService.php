<?php

namespace App\Services;

use App\Models\{Flight, FlightClass, FlightDetail, FlightFareBreakdown};
use App\Services\FlightProviders\FlightProviderInterface;
use Illuminate\Support\Facades\{DB, Log};

class FlightDetailedUpdateService
{
    protected $provider;
    protected $iata;
    protected $service;

    public function __construct(FlightProviderInterface $provider, string $iata, string $service = 'nira')
    {
        $this->provider = $provider;
        $this->iata = $iata;
        $this->service = $service;
    }

    /**
     * متد اصلی برای بروزرسانی تمامی رکوردهای ناقص
     */
    public function updateFlightsDetails(): array
    {
        $stats = [
            'flights_processed' => 0,
            'classes_updated' => 0,
            'details_updated' => 0,
            'breakdowns_created' => 0,
            'errors' => 0
        ];

        // ۱. انتخاب پروازهایی که اطلاعات تکمیلی ندارند یا نیاز به آپدیت دارند
        // فیلتر بر اساس ایرلاین (NV) و سرویس (nira)
        $flights = Flight::with(['activeRoute', 'classes', 'details'])
            ->whereHas('activeRoute', function($q) {
                $q->where('iata', $this->iata)
                  ->where('service', $this->service);
            })
            ->where('departure_datetime', '>=', now()) // فقط پروازهای آتی
            ->get();

        Log::info("Starting detailed update for {$flights->count()} flights of {$this->iata}");

        foreach ($flights as $flight) {
            try {
                DB::beginTransaction();

                $flightUpdated = false;

                // ۲. برای هر کلاس پروازی باید قیمت دقیق و Breakdown را بگیریم
                foreach ($flight->classes as $class) {
                    
                    // فراخوانی متد getFare از پروایدر (NiraProvider)
                    $fareData = $this->provider->getFare(
                        $flight->activeRoute->origin,
                        $flight->activeRoute->destination,
                        $class->class_code,
                        $flight->departure_datetime->format('Y-m-d'),
                        $flight->flight_number
                    );

                    if ($fareData) {
                        // ۳. آپدیت قیمت‌های Child و Infant در جدول flight_classes
                        $class->update([
                            'price_adult' => (float)($fareData['AdultTotalPrice'] ?? $class->price_adult),
                            'price_child' => (float)($fareData['ChildTotalPrice'] ?? 0),
                            'price_infant' => (float)($fareData['InfantTotalPrice'] ?? 0),
                            'last_updated_at' => now(),
                        ]);
                        $stats['classes_updated']++;

                        // ۴. پر کردن جدول flight_fare_breakdown (تفکیک مالیات و قیمت پایه)
                        $this->updateFareBreakdown($class->id, $fareData);
                        $stats['breakdowns_created']++;

                        // ۵. آپدیت جدول flight_details (فقط یکبار برای هر پرواز کافیست)
                        if (!$flightUpdated) {
                            $this->updateFlightDetailsTable($flight->id, $fareData);
                            $stats['details_updated']++;
                            $flightUpdated = true;
                        }
                    }
                }

                $stats['flights_processed']++;
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $stats['errors']++;
                Log::error("Error updating flight ID {$flight->id}: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * آپدیت یا ایجاد جزئیات پرواز (بار، قوانین استرداد، ترانزیت)
     */
    protected function updateFlightDetailsTable(int $flightId, array $fareData)
    {
        FlightDetail::updateOrCreate(
            ['flight_id' => $flightId],
            [
                'arrival_datetime'   => isset($fareData['ArrivalTime']) ? $fareData['ArrivalTime'] : null, // اگر در API باشد
                'has_transit'        => $fareData['HasTransit'] ?? 0,
                'transit_city'       => $fareData['TransitCity'] ?? null,
                'operating_airline'  => $fareData['OperatingAirline'] ?? null,
                'operating_flight_no'=> $fareData['OperatingFlightNo'] ?? null,
                'refund_rules'       => $fareData['CRCNRules'] ?? null, // قوانین کنسلی که در اسکرین‌شات بود
                'baggage_weight'     => $fareData['BaggageAllowanceWeight'] ?? null,
                'baggage_pieces'     => $fareData['BaggageAllowancePieces'] ?? null,
                'last_updated_at'    => now(),
            ]
        );
    }

    /**
     * پر کردن جدول تفکیک قیمت برای انواع مسافر
     */
    protected function updateFareBreakdown(int $classId, array $fareData)
    {
        $passengerTypes = [
            'adult' => ['base' => 'AdultFare', 'taxes' => 'AdultTaxes', 'total' => 'AdultTotalPrice'],
            'child' => ['base' => 'ChildFare', 'taxes' => 'ChildTaxes', 'total' => 'ChildTotalPrice'],
            'infant' => ['base' => 'InfantFare', 'taxes' => 'InfantTaxes', 'total' => 'InfantTotalPrice']
        ];

        foreach ($passengerTypes as $type => $keys) {
            if (!isset($fareData[$keys['base']]) || $fareData[$keys['base']] <= 0) continue;

            // پارس کردن رشته مالیات (مثلاً "I6:1000$V0:500")
            $taxes = $this->parseNiraTaxes($fareData[$keys['taxes']] ?? '');

            FlightFareBreakdown::updateOrCreate(
                ['flight_class_id' => $classId, 'passenger_type' => $type],
                [
                    'base_fare'   => (float)$fareData[$keys['base']],
                    'tax_i6'      => $taxes['I6'] ?? 0,
                    'tax_v0'      => $taxes['V0'] ?? 0,
                    'tax_hl'      => $taxes['HL'] ?? 0,
                    'tax_lp'      => $taxes['LP'] ?? 0,
                    'tax_yq'      => $taxes['YQ'] ?? 0,
                    'total_price' => (float)$fareData[$keys['total']],
                    'last_updated_at' => now(),
                ]
            );
        }
    }

    /**
     * متد کمکی برای استخراج مقادیر مالیات از فرمت متنی نیرا
     */
    protected function parseNiraTaxes(string $taxString): array
    {
        $taxes = [];
        if (empty($taxString)) return $taxes;

        // نیرا معمولاً مالیات‌ها را با $ یا کاما جدا می‌کند
        $parts = preg_split('/[\$,]/', $taxString);
        foreach ($parts as $part) {
            if (str_contains($part, ':')) {
                [$code, $amount] = explode(':', $part);
                $taxes[strtoupper(trim($code))] = (float)$amount;
            }
        }
        return $taxes;
    }
}