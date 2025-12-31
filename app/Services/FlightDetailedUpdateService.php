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

    public function fillMissingData(): array
    {
        $stats = [
            'flights_checked' => 0,
            'classes_updated' => 0,
            'details_updated' => 0,
            'fare_breakdown_created' => 0,
            'errors' => 0
        ];

        // پیدا کردن پروازها - برای تست شرط upcoming حذف شد
        $incompleteFlights = Flight::with(['route', 'classes', 'details'])
            ->whereHas('route', function($q) {
                $q->where('iata', $this->iata)
                  ->where('service', $this->service);
            })
            ->get();

        Log::info("Processing {$incompleteFlights->count()} flights for {$this->iata}");

        foreach ($incompleteFlights as $flight) {
            try {
                DB::beginTransaction();
                $stats['flights_checked']++;
                
                foreach ($flight->classes as $class) {
                    // 1. آپدیت قیمت‌های اصلی (Price Adult, Child, Infant)
                    if ($this->updateClassFareData($flight, $class)) {
                        $stats['classes_updated']++;
                    }
                    
                    // 2. ایجاد یا آپدیت Fare Breakdown (Base Fare + Taxes)
                    $created = $this->createFareBreakdown($flight, $class);
                    $stats['fare_breakdown_created'] += $created;
                }
                
                // 3. آپدیت جزئیات (Baggage, Refund Rules)
                if ($this->updateFlightDetails($flight)) {
                    $stats['details_updated']++;
                }
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $stats['errors']++;
                Log::error("Update failed for flight {$flight->flight_number}: " . $e->getMessage());
            }
        }

        return $stats;
    }

    protected function updateClassFareData(Flight $flight, FlightClass $class): bool
    {
        $fareData = $this->getFareFromProvider($flight, $class->class_code);
        if (!$fareData) return false;

        return $class->update([
            'price_adult' => (float)($fareData['AdultTotalPrice'] ?? $class->price_adult),
            'price_child' => (float)($fareData['ChildTotalPrice'] ?? 0),
            'price_infant' => (float)($fareData['InfantTotalPrice'] ?? 0),
            'last_updated_at' => now(),
        ]);
    }

    protected function createFareBreakdown(Flight $flight, FlightClass $class): int
    {
        $fareData = $this->getFareFromProvider($flight, $class->class_code);
        if (!$fareData) return 0;

        $count = 0;
        $mapping = [
            'adult' => ['base' => 'AdultFare', 'taxes' => 'AdultTaxes', 'total' => 'AdultTotalPrice'],
            'child' => ['base' => 'ChildFare', 'taxes' => 'ChildTaxes', 'total' => 'ChildTotalPrice'],
            'infant' => ['base' => 'InfantFare', 'taxes' => 'InfantTaxes', 'total' => 'InfantTotalPrice']
        ];

        foreach ($mapping as $type => $keys) {
            if (empty($fareData[$keys['base']])) continue;

            $taxes = $this->parseTaxes($fareData[$keys['taxes']] ?? '');
            
            FlightFareBreakdown::updateOrCreate(
                ['flight_class_id' => $class->id, 'passenger_type' => $type],
                [
                    'base_fare' => (float)$fareData[$keys['base']],
                    'tax_i6' => $taxes['I6'] ?? 0,
                    'tax_v0' => $taxes['V0'] ?? 0,
                    'tax_hl' => $taxes['HL'] ?? 0,
                    'tax_lp' => $taxes['LP'] ?? 0,
                    'tax_yq' => $taxes['YQ'] ?? 0,
                    'total_price' => (float)$fareData[$keys['total']],
                    'last_updated_at' => now(),
                ]
            );
            $count++;
        }
        return $count;
    }

    protected function updateFlightDetails(Flight $flight): bool
    {
        $firstClass = $flight->classes->first();
        if (!$firstClass) return false;

        $fareData = $this->getFareFromProvider($flight, $firstClass->class_code);
        if (!$fareData) return false;

        $details = $flight->details ?: new FlightDetail(['flight_id' => $flight->id]);
        $details->baggage_weight = $fareData['BaggageAllowanceWeight'] ?? $details->baggage_weight;
        $details->baggage_pieces = $fareData['BaggageAllowancePieces'] ?? $details->baggage_pieces;
        $details->refund_rules = $fareData['CRCNRules'] ?? $details->refund_rules;
        $details->last_updated_at = now();
        
        return $details->save();
    }

    protected function getFareFromProvider(Flight $flight, string $classCode)
    {
        return $this->provider->getFare(
            $flight->route->origin,
            $flight->route->destination,
            $classCode,
            $flight->departure_datetime->format('Y-m-d'),
            $flight->flight_number
        );
    }

    protected function parseTaxes(string $taxString): array
    {
        $taxes = [];
        // نیرا از $ برای جدا کردن انواع مالیات استفاده می‌کند
        $parts = explode('$', $taxString);
        foreach ($parts as $part) {
            // پیدا کردن الگو مثل I6:100000.0 قبل از رسیدن به کاما
            if (preg_match('/([A-Z0-9]+):([0-9.]+)/', $part, $matches)) {
                $taxes[strtoupper($matches[1])] = (float)$matches[2];
            }
        }
        return $taxes;
    }
}