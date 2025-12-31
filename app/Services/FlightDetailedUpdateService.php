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
     * تکمیل اطلاعات ناقص پروازها
     */
    public function fillMissingData(): array
    {
        $stats = [
            'flights_checked' => 0,
            'classes_updated' => 0,
            'details_updated' => 0,
            'fare_breakdown_created' => 0,
            'errors' => 0
        ];

        // پیدا کردن پروازهای ناقص
        $incompleteFlights = Flight::with(['route', 'classes', 'details'])
            ->whereHas('route', function($q) {
                $q->where('iata', $this->iata)
                  ->where('service', $this->service);
            })
            ->upcoming()
            ->get();

        foreach ($incompleteFlights as $flight) {
            try {
                DB::beginTransaction();
                
                $stats['flights_checked']++;
                
                // بررسی و تکمیل هر کلاس
                foreach ($flight->classes as $class) {
                    if ($this->classNeedsFareData($class)) {
                        $updated = $this->updateClassFareData($flight, $class);
                        if ($updated) $stats['classes_updated']++;
                    }
                    
                    if ($this->classNeedsFareBreakdown($class)) {
                        $created = $this->createFareBreakdown($flight, $class);
                        $stats['fare_breakdown_created'] += $created;
                    }
                }
                
                // تکمیل جزئیات پرواز
                if ($this->flightDetailsNeedsUpdate($flight)) {
                    $updated = $this->updateFlightDetails($flight);
                    if ($updated) $stats['details_updated']++;
                }
                
                DB::commit();
                
            } catch (\Exception $e) {
                DB::rollBack();
                $stats['errors']++;
                Log::error("Detailed update error for flight {$flight->id}: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * بررسی نیاز به آپدیت قیمت‌ها
     */
    protected function classNeedsFareData(FlightClass $class): bool
    {
        return $class->price_child == 0 || $class->price_infant == 0;
    }

    /**
     * بررسی نیاز به ایجاد Fare Breakdown
     */
    protected function classNeedsFareBreakdown(FlightClass $class): bool
    {
        return $class->fareBreakdown->isEmpty();
    }

    /**
     * بررسی نیاز به آپدیت جزئیات پرواز
     */
    protected function flightDetailsNeedsUpdate(Flight $flight): bool
    {
        $details = $flight->details;
        
        if (!$details) return true;
        
        return empty($details->baggage_weight) 
            || empty($details->baggage_pieces)
            || empty($details->refund_rules);
    }

    /**
     * آپدیت قیمت‌های کودک و نوزاد
     */
    protected function updateClassFareData(Flight $flight, FlightClass $class): bool
    {
        $route = $flight->route;
        $date = $flight->departure_datetime->format('Y-m-d');
        
        $fareData = $this->provider->getFare(
            $route->origin,
            $route->destination,
            $class->class_code,
            $date,
            $flight->flight_number
        );
        
        if (!$fareData) {
            Log::warning("No fare data for flight {$flight->flight_number} class {$class->class_code}");
            return false;
        }
        
        $class->update([
            'price_adult' => $fareData['AdultTotalPrice'] ?? $class->price_adult,
            'price_child' => $fareData['ChildTotalPrice'] ?? 0,
            'price_infant' => $fareData['InfantTotalPrice'] ?? 0,
            'last_updated_at' => now(),
        ]);
        
        return true;
    }

    /**
     * ایجاد Fare Breakdown
     */
    protected function createFareBreakdown(Flight $flight, FlightClass $class): int
    {
        $route = $flight->route;
        $date = $flight->departure_datetime->format('Y-m-d');
        
        $fareData = $this->provider->getFare(
            $route->origin,
            $route->destination,
            $class->class_code,
            $date,
            $flight->flight_number
        );
        
        if (!$fareData) {
            return 0;
        }
        
        $created = 0;
        $passengerTypes = [
            'adult' => ['base' => 'AdultFare', 'taxes' => 'AdultTaxes', 'total' => 'AdultTotalPrice'],
            'child' => ['base' => 'ChildFare', 'taxes' => 'ChildTaxes', 'total' => 'ChildTotalPrice'],
            'infant' => ['base' => 'InfantFare', 'taxes' => 'InfantTaxes', 'total' => 'InfantTotalPrice']
        ];

        foreach ($passengerTypes as $type => $keys) {
            if (!isset($fareData[$keys['base']])) continue;

            $taxes = $this->parseTaxes($fareData[$keys['taxes']] ?? '');
            
            FlightFareBreakdown::updateOrCreate(
                [
                    'flight_class_id' => $class->id,
                    'passenger_type' => $type
                ],
                [
                    'base_fare' => $fareData[$keys['base']] ?? 0,
                    'tax_i6' => $taxes['I6'] ?? 0,
                    'tax_v0' => $taxes['V0'] ?? 0,
                    'tax_hl' => $taxes['HL'] ?? 0,
                    'tax_lp' => $taxes['LP'] ?? 0,
                    'tax_yq' => $taxes['YQ'] ?? 0,
                    'total_price' => $fareData[$keys['total']] ?? 0,
                    'last_updated_at' => now(),
                ]
            );
            
            $created++;
        }
        
        return $created;
    }

    /**
     * آپدیت جزئیات پرواز (baggage, refund rules)
     */
    protected function updateFlightDetails(Flight $flight): bool
    {
        $route = $flight->route;
        $date = $flight->departure_datetime->format('Y-m-d');
        
        // گرفتن اطلاعات از اولین کلاس موجود
        $firstClass = $flight->classes->first();
        if (!$firstClass) return false;
        
        $fareData = $this->provider->getFare(
            $route->origin,
            $route->destination,
            $firstClass->class_code,
            $date,
            $flight->flight_number
        );
        
        if (!$fareData) return false;
        
        $details = $flight->details ?: new FlightDetail(['flight_id' => $flight->id]);
        
        // آپدیت فیلدهای خالی
        if (empty($details->baggage_weight) && isset($fareData['BaggageAllowanceWeight'])) {
            $details->baggage_weight = $fareData['BaggageAllowanceWeight'];
        }
        
        if (empty($details->baggage_pieces) && isset($fareData['BaggageAllowancePieces'])) {
            $details->baggage_pieces = $fareData['BaggageAllowancePieces'];
        }
        
        if (empty($details->refund_rules) && isset($fareData['CRCNRules'])) {
            $details->refund_rules = $fareData['CRCNRules'];
        }
        
        $details->last_updated_at = now();
        $details->save();
        
        return true;
    }

    /**
     * پارس کردن عوارض
     */
    protected function parseTaxes(string $taxString): array
    {
        $taxes = [];
        $parts = explode('$', $taxString);
        
        foreach ($parts as $part) {
            if (preg_match('/([A-Z0-9]+):([0-9.]+)/', $part, $matches)) {
                $taxes[$matches[1]] = (float) $matches[2];
            }
        }
        
        return $taxes;
    }
}