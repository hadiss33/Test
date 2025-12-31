<?php

namespace App\Services;

use App\Models\{Flight, FlightClass, FlightDetail, FlightFareBreakdown, AirlineActiveRoute};
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
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

    public function updateByPriority(int $priority): array
    {
        $stats = [
            'checked' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'fare_calls' => 0
        ];

        $routes = AirlineActiveRoute::where('iata', $this->iata)
            ->where('service', $this->service)
            ->get();
            
        $dates = $this->getDatesForPriority($priority);

        foreach ($routes as $route) {
            foreach ($dates as $date) {
                if (!$route->hasFlightOnDate($date)) {
                    continue;
                }

                try {
                    // استفاده از AvailabilityFare برای گرفتن لیست پروازها
                    $flights = $this->provider->getAvailabilityFare(
                        $route->origin,
                        $route->destination,
                        $date->format('Y-m-d')
                    );

                    foreach ($flights as $flightData) {
                        DB::beginTransaction();
                        try {
                            $updateResult = $this->saveFlightWithAllDetails(
                                $route,
                                $flightData,
                                $priority,
                                $date
                            );
                            
                            $stats['checked']++;
                            $stats[$updateResult['status']]++;
                            $stats['fare_calls'] += $updateResult['fare_calls'];
                            
                            DB::commit();
                        } catch (\Exception $e) {
                            DB::rollBack();
                            $stats['errors']++;
                            Log::error("Save Detailed Flight Error: " . $e->getMessage(), [
                                'flight_no' => $flightData['FlightNo'] ?? 'unknown',
                                'route' => "{$route->origin}-{$route->destination}",
                                'date' => $date->format('Y-m-d'),
                                'trace' => $e->getTraceAsString()
                            ]);
                        }
                    }

                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::error("Fetch Availability Error [{$route->origin}-{$route->destination}]: " . $e->getMessage());
                }
            }
        }

        return $stats;
    }

    protected function saveFlightWithAllDetails($route, array $data, int $priority, Carbon $date): array
    {
        $departureDateTime = Carbon::parse($data['DepartureDateTime']);
        
        // ذخیره پرواز
        $flight = Flight::firstOrNew([
            'airline_active_route_id' => $route->id,
            'flight_number' => $data['FlightNo'],
            'departure_datetime' => $departureDateTime,
        ]);

        $isNew = !$flight->exists;
        
        $flight->aircraft_type = $data['AircraftTypeCode'] ?? null;
        $flight->update_priority = $priority;
        $flight->last_updated_at = now();
        $flight->missing_count = 0;
        $flight->save();

        // گرفتن اولین کلاس فعال برای دریافت اطلاعات کامل از Fare API
        $firstActiveClass = null;
        $fareDataForDetails = null;
        
        if (!empty($data['ClassesStatus'])) {
            foreach ($data['ClassesStatus'] as $classData) {
                $status = $this->provider->determineStatus($classData['Cap']);
                if ($status === 'active') {
                    $firstActiveClass = $classData['FlightClass'];
                    
                    // یک بار Fare API را صدا می‌زنیم برای گرفتن اطلاعات تکمیلی
                    $fareDataForDetails = $this->provider->getFare(
                        $route->origin,
                        $route->destination,
                        $firstActiveClass,
                        $date->format('Y-m-d'),
                        $flight->flight_number
                    );
                    break;
                }
            }
        }

        // ذخیره جزئیات پرواز با اطلاعات از Fare API
        $this->saveFlightDetails($flight, $data, $fareDataForDetails);

        // ذخیره کلاس‌ها
        $fareCallsCount = $firstActiveClass ? 1 : 0; // یک بار برای details
        $hasChanges = false;
        
        foreach ($data['ClassesStatus'] as $classData) {
            $result = $this->saveFlightClassWithDetails(
                $flight,
                $route,
                $classData,
                $date
            );
            
            if ($result['changed']) {
                $hasChanges = true;
            }
            $fareCallsCount += $result['fare_calls'];
        }

        return [
            'status' => $isNew ? 'updated' : ($hasChanges ? 'updated' : 'skipped'),
            'fare_calls' => $fareCallsCount
        ];
    }

    protected function saveFlightDetails(Flight $flight, array $availabilityData, ?array $fareData): void
    {
        $detailData = [
            'arrival_datetime' => isset($availabilityData['ArrivalDateTime']) 
                ? Carbon::parse($availabilityData['ArrivalDateTime']) 
                : null,
            'has_transit' => $availabilityData['Transit'] ?? false,
            'transit_city' => null, // این فیلد در API نیرا وجود ندارد
            'operating_airline' => null, // این فیلد در API نیرا وجود ندارد  
            'operating_flight_no' => null, // این فیلد در API نیرا وجود ندارد
            'refund_rules' => $fareData['CRCNRules'] ?? null, // از Fare API
            'baggage_weight' => $fareData['BaggageAllowanceWeight'] ?? null, // از Fare API
            'baggage_pieces' => $fareData['BaggageAllowancePieces'] ?? null, // از Fare API
        ];

        FlightDetail::updateOrCreate(
            ['flight_id' => $flight->id],
            $detailData
        );
    }

    protected function saveFlightClassWithDetails(
        Flight $flight,
        $route,
        array $classData,
        Carbon $date
    ): array {
        $cap = $classData['Cap'];
        $classCode = $classData['FlightClass'];
        $fareCallsCount = 0;
        
        // بررسی وضعیت کلاس - اگر بسته است Fare API نمی‌زنیم
        $status = $this->provider->determineStatus($cap);
        $availableSeats = $this->provider->parseAvailableSeats($cap, $classCode);
        
        $fareData = null;
        $priceAdult = 0;
        $priceChild = 0;
        $priceInfant = 0;

        // فقط برای کلاس‌های فعال Fare API را صدا می‌زنیم
        if ($status === 'active' && $availableSeats > 0) {
            $price = $classData['Price'] ?? '0';
            
            // اگر قیمت در AvailabilityFare موجود است
            if ($price !== '-' && is_numeric($price) && $price > 0) {
                $priceAdult = (float) $price;
                
                // برای گرفتن قیمت child و infant باید Fare API را صدا بزنیم
                $fareData = $this->provider->getFare(
                    $route->origin,
                    $route->destination,
                    $classCode,
                    $date->format('Y-m-d'),
                    $flight->flight_number
                );
                
                $fareCallsCount++;
                
                if ($fareData) {
                    $priceAdult = $fareData['AdultTotalPrice'] ?? $priceAdult;
                    $priceChild = $fareData['ChildTotalPrice'] ?? 0;
                    $priceInfant = $fareData['InfantTotalPrice'] ?? 0;
                }
            } else {
                // اگر قیمت نیست، حتماً باید Fare API را صدا بزنیم
                $fareData = $this->provider->getFare(
                    $route->origin,
                    $route->destination,
                    $classCode,
                    $date->format('Y-m-d'),
                    $flight->flight_number
                );
                
                $fareCallsCount++;
                
                if ($fareData) {
                    $priceAdult = $fareData['AdultTotalPrice'] ?? 0;
                    $priceChild = $fareData['ChildTotalPrice'] ?? 0;
                    $priceInfant = $fareData['InfantTotalPrice'] ?? 0;
                }
            }
        }

        $newData = [
            'class_status' => $cap,
            'price_adult' => $priceAdult,
            'price_child' => $priceChild,
            'price_infant' => $priceInfant,
            'available_seats' => $availableSeats,
            'status' => $status,
            'last_updated_at' => now(),
        ];

        $flightClass = FlightClass::where('flight_id', $flight->id)
            ->where('class_code', $classCode)
            ->first();

        if (!$flightClass) {
            // ایجاد کلاس جدید
            $flightClass = FlightClass::create(array_merge([
                'flight_id' => $flight->id,
                'class_code' => $classCode,
            ], $newData));

            // ذخیره Fare Breakdown فقط اگر fareData موجود باشد
            if ($fareData) {
                $this->saveFareBreakdown($flightClass, $fareData);
            }
            
            return ['changed' => true, 'fare_calls' => $fareCallsCount];
        }

        // بررسی تغییرات
        $hasChanges = 
            $flightClass->class_status != $newData['class_status'] ||
            $flightClass->price_adult != $newData['price_adult'] ||
            $flightClass->price_child != $newData['price_child'] ||
            $flightClass->price_infant != $newData['price_infant'] ||
            $flightClass->available_seats != $newData['available_seats'] ||
            $flightClass->status != $newData['status'];

        if ($hasChanges) {
            $flightClass->update($newData);
        }

        // ذخیره Fare Breakdown فقط اگر fareData موجود باشد
        if ($fareData) {
            $this->saveFareBreakdown($flightClass, $fareData);
        }

        return ['changed' => $hasChanges, 'fare_calls' => $fareCallsCount];
    }

    protected function saveFareBreakdown(FlightClass $flightClass, array $fareData): void
    {
        $passengerTypes = [
            'adult' => ['base' => 'AdultFare', 'taxes' => 'AdultTaxes', 'total' => 'AdultTotalPrice'],
            'child' => ['base' => 'ChildFare', 'taxes' => 'ChildTaxes', 'total' => 'ChildTotalPrice'],
            'infant' => ['base' => 'InfantFare', 'taxes' => 'InfantTaxes', 'total' => 'InfantTotalPrice']
        ];

        foreach ($passengerTypes as $type => $keys) {
            if (!isset($fareData[$keys['base']])) {
                continue;
            }

            $taxes = $this->parseTaxes($fareData[$keys['taxes']] ?? '');
            
            FlightFareBreakdown::updateOrCreate(
                [
                    'flight_class_id' => $flightClass->id,
                    'passenger_type' => $type
                ],
                [
                    'base_fare' => $fareData[$keys['base']],
                    'tax_i6' => $taxes['I6'] ?? 0,
                    'tax_v0' => $taxes['V0'] ?? 0,
                    'tax_hl' => $taxes['HL'] ?? 0,
                    'tax_lp' => $taxes['LP'] ?? 0,
                    'total_price' => $fareData[$keys['total']],
                    'last_updated_at' => now(),
                ]
            );
        }
    }

    protected function parseTaxes(string $taxString): array
    {
        $taxes = [];
        
        // فرمت: "I6:30000.0,EN_Desc:...$V0:495000.0,EN_Desc:...$HL:55000.0..."
        $parts = explode('$', $taxString);
        
        foreach ($parts as $part) {
            // پیدا کردن کد مالیات و مقدار آن
            if (preg_match('/([A-Z0-9]+):([0-9.]+)/', $part, $matches)) {
                $taxes[$matches[1]] = (float) $matches[2];
            }
        }
        
        return $taxes;
    }

    protected function getDatesForPriority(int $priority): array
    {
        $dates = [];
        $ranges = [
            1 => [0, 3],
            2 => [4, 7],
            3 => [8, 30],
            4 => [31, 120],
        ];

        [$start, $end] = $ranges[$priority] ?? [0, 3];

        for ($i = $start; $i <= $end; $i++) {
            $dates[] = now()->addDays($i);
        }

        return $dates;
    }
}