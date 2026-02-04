<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class SnapFlightResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // دسترسی سریع به سگمنت اصلی پرواز (فرض بر یک طرفه بودن یا سگمنت اول)
        $segment = $this['originDestinationOptions'][0]['flightSegments'][0] ?? [];
        $options = $request->input('options', []);

        return [
            // چون دیتای خارجی است، ID دیتابیس ندارد
            'charter_id' => null, 
            'serial'     => $segment['flightNumber'] ?? '',
            'supplier'   => 2, // عدد 2 را به عنوان نماد سرویس اسنپ در نظر گرفتیم (یا هر کدی که قرارداد دارید)
            
            // تولید شناسه یکتا بر اساس fareSourceCode که برای بوک کردن اسنپ حیاتی است
            'id'         => $this->generateUniqueId(), 
            'plan'       => false,

            'details'    => $this->formatDetails($segment),
            'items'      => [$this->formatItem($segment, $options)], // اسنپ یک کلاس نرخ برمی‌گرداند، پس در آرایه می‌گذاریم
            'description'=> $this->formatDescription(),
        ];
    }

    protected function formatDetails(array $segment): array
    {
        $departureTime = $segment['departureDateTime'] ?? null;
        $arrivalTime = $segment['arrivalDateTime'] ?? null;
        
        // محاسبه مدت زمان اگر موجود نبود
        $duration = $segment['journeyDurationPerMinute'] ?? 0;
        if ($duration == 0 && $departureTime && $arrivalTime) {
            $duration = Carbon::parse($departureTime)->diffInMinutes(Carbon::parse($arrivalTime));
        }

        return [
            'origin' => [
                'iata'     => $segment['departureAirportLocationCode'] ?? '',
                'terminal' => null, // اسنپ معمولا ترمینال را در سرچ نمی‌دهد
            ],
            
            'destination' => [
                'iata'     => $segment['arrivalAirportLocationCode'] ?? '',
                'terminal' => null,
            ],
            
            'aircraft' => [
                'iata'  => $segment['operatingAirline']['equipment'] ?? '',
                'icao'  => $segment['operatingAirline']['equipment'] ?? '', // کد تجهیزات
                'title' => [
                    'en' => $this->getAircraftNameEn($segment['operatingAirline']['equipment'] ?? ''),
                    'fa' => $this->getAircraftNameFa($segment['operatingAirline']['equipment'] ?? ''),
                ],
            ],
            
            'flight_number'    => (string) ($segment['flightNumber'] ?? ''),
            'steps'            => ($segment['stopQuantity'] ?? 0) > 0 ? 1 : 0, // اگر توقف داشت
            'duration'         => $duration,
            'datetime'         => $this->formatDateTime($departureTime),
            'arrival_datetime' => $this->formatDateTime($arrivalTime),
            
            // اضافه کردن اطلاعات ایرلاین که در ریسورس اصلی بود
             'airline' => [
                 'iata' => $segment['marketingAirlineCode'] ?? '',
                 'icao' => '',
                 'logo' => $this->getAirlineLogo($segment['marketingAirlineCode'] ?? ''),
                 'title' => [
                     'en' => $this->getAirlineNameEn($segment['marketingAirlineCode'] ?? ''),
                     'fa' => $this->getAirlineNameFa($segment['marketingAirlineCode'] ?? ''),
                 ],
             ],
        ];
    }

    protected function formatItem(array $segment, array $options): array
    {
        // استخراج اطلاعات مالی از ساختار پیچیده اسنپ
        $financials = $this->extractFinancials();
        $baggageInfo = $this->parseBaggage($segment['baggage'] ?? '');

        // پیدا کردن ظرفیت
        $capacity = $segment['seatsRemaining'] ?? 9;

        return [
            'item_id'      => $this['fareSourceCode'] ?? null, // شناسه اصلی برای بوک کردن
            'title'        => $segment['resBookDesigCode'] ?? 'Y', // کلاس پروازی
            'reservable'   => $capacity > 0,
            
            'statistics'   => [
                'capacity' => $capacity,
                'waiting'  => 0,
            ],
            
            'max_purchase' => $capacity,
            
            // اسنپ در سرچ، قوانین استرداد (Rules) را دقیق برنمی‌گرداند یا ساختارش متفاوت است
            // فعلاً خالی می‌گذاریم یا باید از دیتای دیگری مپ شود
            'rules'        => null, 
            'services'     => null,
            
            'baggage'      => $this->formatBaggage($baggageInfo),
            'financial'    => $financials,
        ];
    }

    protected function formatBaggage(int $weight): array
    {
        // تبدیل وزن استخراج شده به ساختار استاندارد
        return [
            'trunk' => [
                'adult'  => ['number' => 1, 'weight' => $weight],
                'child'  => ['number' => 1, 'weight' => $weight],
                'infant' => ['number' => 0, 'weight' => 0], // معمولا نوزاد بار ندارد یا کمتر است
            ],
            'hand' => [
                'adult'  => ['number' => 1, 'weight' => 7], // استاندارد تقریبی
                'child'  => ['number' => 1, 'weight' => 7],
                'infant' => ['number' => 0, 'weight' => 0],
            ],
        ];
    }

    protected function parseBaggage(string $baggageString): int
    {
        // تبدیل "30KG" یا "20 Kilograms" به عدد 30 یا 20
        if (preg_match('/(\d+)/', $baggageString, $matches)) {
            return (int) $matches[1];
        }
        return 20; // مقدار پیش‌فرض اگر پیدا نشد
    }

    protected function extractFinancials(): array
    {
        // مقادیر پیش‌فرض
        $prices = [
            'adult'  => $this->getEmptyFinancial(),
            'child'  => $this->getEmptyFinancial(),
            'infant' => $this->getEmptyFinancial(),
        ];

        // مپ کردن نوع مسافر اسنپ به کلیدهای ما
        // Snapp: 1=Adult, 2=Child, 3=Infant
        $typeMap = [
            1 => 'adult',
            2 => 'child',
            3 => 'infant'
        ];

        $breakdowns = $this['airItineraryPricingInfo']['ptcFareBreakdown'] ?? [];

        foreach ($breakdowns as $ptc) {
            $passengerType = $ptc['passengerTypeQuantity']['passengerType'] ?? null;
            $key = $typeMap[$passengerType] ?? null;

            if ($key) {
                $fareInfo = $ptc['passengerFare'] ?? [];
                
                $baseFare = (float) ($fareInfo['baseFare'] ?? 0);
                $totalFare = (float) ($fareInfo['totalFare'] ?? 0);
                // مالیات در اسنپ اختلاف توتال و بیس است
                $tax = $totalFare - $baseFare;

                $prices[$key] = [
                    'base_fare'   => $baseFare,
                    'taxes'       => $tax, // مجموع مالیات
                    'total_fare'  => $totalFare,
                    'payable'     => $totalFare,
                    'markups'     => 0,
                    'commissions' => 0,
                    'citizenship' => 0,
                ];
            }
        }

        return $prices;
    }

    protected function getEmptyFinancial(): array
    {
        return [
            'base_fare'   => 0,
            'taxes'       => 0,
            'total_fare'  => 0,
            'payable'     => 0,
            'markups'     => 0,
            'commissions' => 0,
            'citizenship' => 0,
        ];
    }

    protected function formatDescription(): array
    {
        return [
            'public'    => 0,
            'financial' => 0,
        ];
    }

    protected function generateUniqueId(): string
    {
        // استفاده از fareSourceCode به عنوان شناسه یکتا برای ارجاع بعدی
        return base64_encode($this['fareSourceCode'] ?? uniqid());
    }

    protected function formatDateTime($datetime): string|false
    {
        if (!$datetime) return false;
        try {
            return Carbon::parse($datetime)->format('Y-m-d H:i');
        } catch (\Exception $e) {
            return false;
        }
    }

    // --- Helper Methods (Copied from FlightResource to maintain consistency without dependency) ---

    protected function getAirlineLogo(string $iata): string
    {
        return $iata ? asset("images/airlines/{$iata}.png") : '';
    }

    protected function getAirlineNameEn(string $iata): string
    {
        $namesEn = [
            'EP' => 'Iran Air', 'FP' => 'Fly Persia', 'HH' => 'Taban Air',
            'IV' => 'Caspian Airlines', 'I3' => 'ATA Airlines', 'NV' => 'Karun Airlines',
            'PA' => 'Pars Air', 'Y9' => 'Kish Air', 'ZV' => 'Zagros Airlines',
            'QB' => 'Qeshm Air', 'EK' => 'Emirates', 'TK' => 'Turkish Airlines'
        ];
        return $namesEn[$iata] ?? $iata;
    }

    protected function getAirlineNameFa(string $iata): string
    {
        $namesFa = [
            'EP' => 'ایران ایر', 'FP' => 'فلای پرشیا', 'HH' => 'تابان',
            'IV' => 'کاسپین', 'I3' => 'آتا', 'NV' => 'کارون',
            'PA' => 'پارس ایر', 'Y9' => 'کیش ایر', 'ZV' => 'زاگرس',
            'QB' => 'قشم ایر', 'EK' => 'امارات', 'TK' => 'ترکیش'
        ];
        return $namesFa[$iata] ?? $iata;
    }

    protected function getAircraftNameEn(string $code): string
    {
        $types = [
            'MD8' => 'McDonnell Douglas MD-80', '733' => 'Boeing 737-300',
            '738' => 'Boeing 737-800', 'AT7' => 'ATR 72', 'AT5' => 'ATR 42',
            'F100' => 'Fokker 100', '77W' => 'Boeing 777-300ER', '320' => 'Airbus A320'
        ];
        return $types[$code] ?? $code;
    }

    protected function getAircraftNameFa(string $code): string
    {
        $types = [
            'MD8' => 'مک‌دانل داگلاس ام‌دی-۸۰', '733' => 'بوئینگ ۷۳۷-۳۰۰',
            '738' => 'بوئینگ ۷۳۷-۸۰۰', 'AT7' => 'ای‌تی‌آر ۷۲', 'AT5' => 'ای‌تی‌آر ۴۲',
            'F100' => 'فوکر ۱۰۰', '77W' => 'بوئینگ ۷۷۷', '320' => 'ایرباس ۳۲۰'
        ];
        return $types[$code] ?? $code;
    }

    public function with(Request $request): array
    {
        return [
            'meta' => [
                'timestamp' => now()->toDateTimeString(),
            ],
        ];
    }
}