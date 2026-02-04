<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;

class FlightResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $options = $request->input('options', []);
        
        return [
            'charter_id' => $this->id ?? false,
            'serial' => $this->flight_number ?? false,
            'supplier' => 1,
            'id' => $this->generateUniqueId(),
            'plan' => false,
            
            'details' => $this->formatDetails(),
            'items' => $this->formatItems($options),
            'description' => $this->formatDescription(),
        ];
    }


    protected function formatDetails(): array
    {
        return [
            // 'airline' => [
            //     'iata' => $this->route->iata ?? '',
            //     'icao' => $this->getAirlineIcao(),
            //     'logo' => $this->getAirlineLogo(),
            //     'title' => [
            //         'en' => $this->getAirlineNameEn(),
            //         'fa' => $this->getAirlineNameFa(),
            //     ],
            // ],
            
            'origin' => [
                'iata' => $this->route->origin ?? '',
                'terminal' => $this->details->origin_terminal ?? null,
            ],
            
            'destination' => [
                'iata' => $this->route->destination ?? '',
                'terminal' => $this->details->destination_terminal ?? null,
            ],
            
            'aircraft' => [
                'iata' => $this->details->aircraft_code ?? '',
                'icao' => $this->details->aircraft_type_code ?? '',
                'title' => [
                    'en' => $this->getAircraftNameEn(),
                    'fa' => $this->getAircraftNameFa(),
                ],
            ],
            
            'flight_number' => (string) $this->flight_number,
            'steps' => $this->details->has_transit ?? null,
            'duration' => $this->details->flight_duration ?? null,
            'datetime' => $this->formatDateTime($this->departure_datetime),
            'arrival_datetime' => $this->formatDateTime($this->details?->arrival_datetime) ?: null,
        ];
    }


    protected function formatItems(array $options): array
    {
        if (!$this->relationLoaded('classes')) {
            return [];
        }

        return $this->classes->map(function ($class) use ($options) {
            return [
                'item_id' => $class->id,
                'title' => $class->class_code,
                'reservable' => $class->isAvailable(),
                
                'statistics' => [
                    'capacity' => $class->available_seats ?? 0,
                    'waiting' => 0, 
                ],
                
                'max_purchase' => $class->available_seats ?? 9,
                'rules' => $this->formatRules($class, $options),
                'services' => $this->formatServices($class) ?: null,
                'baggage' => $this->formatBaggage($class, $options),
                'financial' => $this->formatFinancial($class, $options),
            ];
        })->toArray();
    }


    protected function formatRules($class, array $options): mixed
    {
        if (!in_array('Rule', $options) || !$class->relationLoaded('rules')) {
            return null;
        }

        if ($class->rules->isEmpty()) {
            return null;
        }

        return $class->rules->map(function ($rule) {
            return [
                'text' => $rule->rules ?? '',
                'penalty_percentage' => $rule->penalty_percentage ?? 0,
            ];
        })->toArray();
    }


    protected function formatServices($class): mixed
    {
        return false;
    }


    protected function formatBaggage($class, array $options): array
    {
        $defaultBaggage = [
            'trunk' => [
                'adult' => ['number' => 1, 'weight' => 20],
                'child' => ['number' => 1, 'weight' => 20],
                'infant' => ['number' => 0, 'weight' => 0],
            ],
            'hand' => [
                'adult' => ['number' => 1, 'weight' => 7],
                'child' => ['number' => 1, 'weight' => 7],
                'infant' => ['number' => 0, 'weight' => 0],
            ],
        ];

        if (!in_array('Baggage', $options) || !$class->relationLoaded('fareBaggage')) {
            return $defaultBaggage;
        }

        $baggage = $class->fareBaggage->first();

        if (!$baggage) {
            return $defaultBaggage;
        }

        return [
            'trunk' => [
                'adult' => [
                    'number' => $baggage->adult_pieces ?? 1,
                    'weight' => $baggage->adult_weight ?? 20,
                ],
                'child' => [
                    'number' => $baggage->child_pieces ?? 1,
                    'weight' => $baggage->child_weight ?? 20,
                ],
                'infant' => [
                    'number' => $baggage->infant_pieces ?? 0,
                    'weight' => $baggage->infant_weight ?? 0,
                ],
            ],
            'hand' => [
                'adult' => ['number' => 1, 'weight' => 7],
                'child' => ['number' => 1, 'weight' => 7],
                'infant' => ['number' => 0, 'weight' => 0],
            ],
        ];
    }

    protected function formatFinancial($class, array $options): array
    {
        $showFareBreakdown = in_array('FareBreakdown', $options);
        $showTax = in_array('Tax', $options) || in_array('TaxDetails', $options);

        return [
            'adult' => $this->formatPassengerFinancial($class, 'adult', $showFareBreakdown, $showTax),
            'child' => $this->formatPassengerFinancial($class, 'child', $showFareBreakdown, $showTax),
            'infant' => $this->formatPassengerFinancial($class, 'infant', $showFareBreakdown, $showTax),
        ];
    }

    protected function formatPassengerFinancial($class, string $type, bool $showBreakdown, bool $showTax): array
    {
        $priceField = "payable_{$type}";
        $totalPrice = (float) ($class->$priceField ?? 0);

        $baseFare = $totalPrice;
        if ($showBreakdown && $class->relationLoaded('fareBreakdown')) {
            $breakdown = $class->fareBreakdown->first();
            if ($breakdown) {
                $baseFare = (float) ($breakdown->{"base_{$type}"} ?? $totalPrice);
            }
        }

        $taxes = false;
        if ($showTax && $class->relationLoaded('taxes')) {
            $taxRecord = $class->taxes->where('passenger_type', $type)->first();
            if ($taxRecord) {
                $taxes = $this->calculateTotalTax($taxRecord);
            }
        }

        return [
            'base_fare' => $baseFare,
            'taxes' => $taxes,
            'total_fare' => $totalPrice,
            'payable' => $totalPrice,
            'markups' => 0, 
            'commissions' => 0, 
            'citizenship' => 0, 
        ];
    }


    protected function calculateTotalTax($taxRecord): float
    {
        $total = 0.0;
        $taxColumns = ['HL', 'I6', 'LP', 'V0', 'YQ'];
        
        foreach ($taxColumns as $col) {
            $total += (float) ($taxRecord->$col ?? 0);
        }
        
        return $total;
    }


    protected function formatDescription(): array
    {
        return [
            'public' => 0,
            'financial' => 0, 
        ];
    }


    protected function generateUniqueId(): string
    {
        return base64_encode($this->id . '-' . $this->departure_datetime->timestamp);
    }

    protected function formatDateTime($datetime): string|false
    {
        if (!$datetime) {
            return false;
        }

        try {
            return \Carbon\Carbon::parse($datetime)->format('Y-m-d H:i');
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function getAirlineIcao(): string
    {
        $icaoMap = [
            'EP' => 'IRA',
            'FP' => 'FPI',
            'HH' => 'TBN',
            'IV' => 'CPN',
            'I3' => 'IRC',
            'NV' => 'KRN',
            'PA' => 'PRS',
            'Y9' => 'KIS',
            'ZV' => 'IZG',
            'QB' => 'QSM',
        ];

        $iata = $this->route->iata ?? '';
        return $icaoMap[$iata] ?? '';
    }

    protected function getAirlineLogo(): string
    {
        $iata = $this->route->iata ?? '';
        return $iata ? asset("images/airlines/{$iata}.png") : '';
    }

    protected function getAirlineNameEn(): string
    {
        $namesEn = [
            'EP' => 'Iran Air',
            'FP' => 'Fly Persia',
            'HH' => 'Taban Air',
            'IV' => 'Caspian Airlines',
            'I3' => 'ATA Airlines',
            'NV' => 'Karun Airlines',
            'PA' => 'Pars Air',
            'Y9' => 'Kish Air',
            'ZV' => 'Zagros Airlines',
            'QB' => 'Qeshm Air',
        ];

        $iata = $this->route->iata ?? '';
        return $namesEn[$iata] ?? '';
    }

    protected function getAirlineNameFa(): string
    {
        $namesFa = [
            'EP' => 'ایران ایر',
            'FP' => 'فلای پرشیا',
            'HH' => 'تابان',
            'IV' => 'کاسپین',
            'I3' => 'آتا',
            'NV' => 'کارون',
            'PA' => 'پارس ایر',
            'Y9' => 'کیش ایر',
            'ZV' => 'زاگرس',
            'QB' => 'قشم ایر',
        ];

        $iata = $this->route->iata ?? '';
        return $namesFa[$iata] ?? '';
    }

    protected function getAircraftNameEn(): string
    {
        $types = [
            'MD8' => 'McDonnell Douglas MD-80',
            '733' => 'Boeing 737-300',
            '738' => 'Boeing 737-800',
            'AT7' => 'ATR 72',
            'AT5' => 'ATR 42',
            'F100' => 'Fokker 100',
        ];

        $code = $this->details?->aircraft_type_code ?? '';
        return $types[$code] ?? '';
    }

    protected function getAircraftNameFa(): string
    {
        $types = [
            'MD8' => 'مک‌دانل داگلاس ام‌دی-۸۰',
            '733' => 'بوئینگ ۷۳۷-۳۰۰',
            '738' => 'بوئینگ ۷۳۷-۸۰۰',
            'AT7' => 'ای‌تی‌آر ۷۲',
            'AT5' => 'ای‌تی‌آر ۴۲',
            'F100' => 'فوکر ۱۰۰',
        ];

        $code = $this->details?->aircraft_type_code ?? '';
        return $types[$code] ?? '';
    }

    public function with(Request $request): array
    {
        return [
            'meta' => [
                'timestamp' => now()->toDateTimeString(),
            ],
        ];
    }

    public static function collection($resource)
    {
        return parent::collection($resource)->additional([
            'meta' => [
                'timestamp' => now()->toDateTimeString(),
            ],
        ]);
    }
}