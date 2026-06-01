<?php

namespace App\Services\OTA\Flights\Infrastructure\Persistence;

use App\Services\OTA\Flights\Domain\Repositories\V2\FlightDictionaryRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class FlightDictionaryRepository implements FlightDictionaryRepositoryInterface
{
    /**
     * دریافت اطلاعات کامل فرودگاه به همراه شهر، استان و کشور
     */
    public function getAirport(string $iata, bool $details = true): array|string
    {
        if (! $details) {
            return ['iata' => $iata];
        }

        $cacheKey = 'airports:v2:'.strtoupper($iata);
        $cached = Redis::get($cacheKey);

        if ($cached) {
            return json_decode($cached, true);
        }

        $record = DB::table('airports')
            ->select(
                'airports.id', 'airports.iata', 'airports.title', 'airports.title_fa', 'airports.priority', 'airports.status',
                'countries.id as country_id', 'countries.fa_name as country_title_fa', 'countries.en_name as country_title_en',
                'states.id as state_id', 'states.fa_name as state_title_fa', 'states.en_name as state_title_en',
                'cities.id as city_id', 'cities.fa_name as city_title_fa', 'cities.en_name as city_title_en'
            )
            ->leftJoin('countries', 'countries.id', '=', 'airports.country')
            ->leftJoin('states', 'states.id', '=', 'airports.state')
            ->leftJoin('cities', 'cities.id', '=', 'airports.city')
            ->where('airports.iata', strtoupper($iata))
            ->first();

        if (! $record) {
            return ['iata' => $iata]; // Fallback
        }

        $airport = [
            'id' => $record->id,
            'iata' => $record->iata,
            'title' => $record->title,
            'title_fa' => $record->title_fa,
            'country' => [
                'id' => $record->country_id,
                'title_fa' => $record->country_title_fa,
                'title_en' => $record->country_title_en,
            ],
            'state' => [
                'id' => $record->state_id,
                'title_fa' => $record->state_title_fa,
                'title_en' => $record->state_title_en,
            ],
            'city' => [
                'id' => $record->city_id,
                'title_fa' => $record->city_title_fa,
                'title_en' => $record->city_title_en,
            ],
            'priority' => $record->priority,
            'status' => $record->status,
        ];

        Redis::setex($cacheKey, 86400, json_encode($airport)); // کش برای 24 ساعت

        return $airport;
    }

    /**
     * دریافت اطلاعات ایرلاین (هواپیمایی) و لوگو
     */
    public function getAircraft(string $idOrIata, bool $details = true): array|string
    {
        if (! $details || empty($idOrIata)) {
            return ['iata' => $idOrIata];
        }

        $cacheKey = 'aircrafts:v2:'.strtoupper($idOrIata);
        $cached = Redis::get($cacheKey);

        if ($cached) {
            return json_decode($cached, true);
        }

        try {
            // اگر اسم جدول هواپیماها تو سیستم شما چیز دیگه‌ای هست (مثل aircraft)، اینجا عوضش کن
            $record = DB::table('aircrafts')
                ->where('iata', strtoupper($idOrIata))
                ->orWhere('icao', strtoupper($idOrIata))
                ->first();

            if (! $record) {
                return ['iata' => $idOrIata];
            }

            $aircraft = [
                'iata' => $record->iata,
                'icao' => $record->icao,
                'title' => [
                    'en' => $record->title ?? '',
                    'fa' => $record->title_fa ?? null,
                ],
            ];

            Redis::setex($cacheKey, 86400, json_encode($aircraft));

            return $aircraft;

        } catch (\Throwable $e) {
            // اگر جدول تو دیتابیس نبود، کرش نکن! همون کد خام رو برگردون
            return ['iata' => $idOrIata];
        }
    }

    /**
     * دریافت اطلاعات ایرلاین و لوگو (مقاوم در برابر نبود جدول)
     */
    public function getAirline(string $iataOrIcao, bool $details = true): array|string
    {
        if (! $details || empty($iataOrIcao)) {
            return ['iata' => $iataOrIcao];
        }

        $cacheKey = 'airlines:v2:'.strtoupper($iataOrIcao);
        $cached = Redis::get($cacheKey);

        if ($cached) {
            return json_decode($cached, true);
        }

        try {
            $record = DB::table('airlines')
                ->select('id', 'iata', 'icao', 'logo', 'title', 'title_fa', 'country', 'priority', 'status')
                ->where('iata', strtoupper($iataOrIcao))
                ->orWhere('icao', strtoupper($iataOrIcao))
                ->first();

            if (! $record) {
                return ['iata' => $iataOrIcao];
            }

            $country = DB::table('countries')->select('id', 'fa_name as title_fa', 'en_name as title_en')->where('id', $record->country)->first();

            $airline = [
                'id' => $record->id,
                'iata' => $record->iata,
                'icao' => $record->icao,
                'logo' => $record->logo,
                'media' => [
                    'logo' => [
                        'small' => "/media/logo/airline/small/{$record->iata}.png",
                        'large' => "/media/logo/airline/large/{$record->iata}.png",
                    ],
                ],
                'title' => $record->title,
                'title_fa' => $record->title_fa,
                'country' => $country ? (array) $country : null,
                'priority' => $record->priority,
                'status' => $record->status,
            ];

            Redis::setex($cacheKey, 86400, json_encode($airline));

            return $airline;

        } catch (\Throwable $e) {
            // در صورت عدم وجود جدول
            return ['iata' => $iataOrIcao];
        }
    }

    /**
     * دریافت اطلاعات تامین‌کننده سیستم
     */
    public function getSupplier(mixed $systemSupplier, string|int|false $branch = false, bool $isHub = false): array
    {
        // در اینجا دقیقاً لاجیک V1 که با mapping_colleagues کار می‌کرد پیاده می‌شود.
        // برای نمونه، یک ساختار استاندارد برمی‌گردانیم:
        return [
            'id' => $systemSupplier,
            'title_fa' => 'تامین‌کننده '.$systemSupplier,
            'title_en' => 'Supplier '.$systemSupplier,
            'first_name' => null,
            'last_name' => null,
            'credit_amount' => null,
            'status' => 1,
        ];
    }
}
