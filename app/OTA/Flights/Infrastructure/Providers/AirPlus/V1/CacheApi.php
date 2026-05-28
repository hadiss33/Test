<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\AirPlus\V1;

use App\Http\Controllers\Api\Panel\V2\StaticController;
use App\Traits\FlightCacheResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class CacheApi
{
    use FlightCacheResponseTrait;

    public static function getFlights($data, $branch, $airline, Request $request)
    {

        try {
            $result = Http::timeout(120)
                ->withToken($request->bearerToken())
                ->get(
                    env('CACHE_SERVICE_BASE_URL') . 'flights/advanced-search',
                    [
                        'datetime_start' => $data['DepartureDate'],
                        'datetime_end' => $data['ReturningDate'],
                        'origin' => $data['OriginIataCode'],
                        'destination' => $data['DestinationIataCode'],
                        'return' => $data['Returning'],
                    ]
                )
                ->throw()
                ->json();

            $Flights = self::mapCacheResponseToSystem($result['data'], false, $branch, $airline, 'snapptrip_flight', SnappTripApi::class);

            if (isset($Flights)) {
                $arr = [
                    'Status' => true,
                    'Time' => time(),
                    'CurrencyCode' => 'IRR',
                    'Information' => $Flights,
                ];
            } else {
                $arr = [
                    'Status' => false,
                    'Time' => time(),
                    'Code' => '1503',
                    'Message' => false,
                    'Data' => $data['AvailableFlights'] ?? [],
                ];
            }

            return ['Data' => $arr, 'Result' => $data];
        } catch (\Exception $e) {

            return ['Data' => ['Status' => false, 'Message' => $e->getMessage()], 'Result' => []];
        }
    }

    public static function getDetails($action, $data, $details = false, $branch = false, $airline = false)
    {
        if ($branch) {
            $tempBranch = DB::table('offices')->select('base_online')->where('id', $branch)->first();
            if (is_null($tempBranch->base_online)) {
                $data = 1;
            }
        }
        if (! $details) {
            return $data;
        } else {
            if ($action == 'airline') {
                $iataAirline = DB::table('airlines')->select('id')->where('iata', $data)->orWhere('icao', $data)->first();
                if ($iataAirline) {
                    $airline = StaticController::dataRedis('airline', $iataAirline->id);

                    return $airline;
                } else {
                    return ['iata' => $data];
                }
            } elseif ($action == 'aircraft') {
                $aircraft = json_decode(Redis::get('aircraft:' . $data), true);
                if (! $aircraft) {
                    $aircraft = DB::table('aircraft')->where('id', $data)->first();
                    if (! is_null($aircraft)) {
                        $aircraft = [
                            'id' => $aircraft->id,
                            'iata' => $aircraft->iata,
                            'icao' => $aircraft->icao,
                            'logo' => $aircraft->image,
                            'model' => $aircraft->model,
                        ];
                        Redis::set('aircraft:' . $data, json_encode($aircraft));
                    } else {
                        $aircraft = ['iata' => $data];
                    }
                }

                return $aircraft;
            } elseif ($action == 'airport') {
                $airport = json_decode(Redis::get('airports:' . $data), true);
                if (! $airport) {
                    $airport = DB::table('airports')
                        ->select(
                            'airports.id as id',
                            'airports.iata as iata',
                            'airports.title as title',
                            'airports.title_fa as title_fa',
                            'airports.country as country',
                            'airports.state as state',
                            'airports.priority as priority',
                            'airports.status as status',
                            'countries.id as country_id',
                            'countries.fa_name as country_title_fa',
                            'countries.en_name as country_title_en',
                            'states.id as state_id',
                            'states.fa_name as state_title_fa',
                            'states.en_name as state_title_en',
                            'cities.id as city_id',
                            'cities.fa_name as city_title_fa',
                            'cities.en_name as city_title_en',
                        )
                        ->where('airports.iata', $data)
                        ->leftJoin('countries', 'countries.id', 'airports.country')
                        ->leftJoin('states', 'states.id', 'airports.state')
                        ->leftJoin('cities', 'cities.id', 'airports.city')
                        ->first();
                    $airport = [
                        'id' => $airport->id,
                        'iata' => $airport->iata,
                        'title' => $airport->title,
                        'title_fa' => $airport->title_fa,
                        'country' => [
                            'id' => $airport->country_id,
                            'title_fa' => $airport->country_title_fa,
                            'title_en' => $airport->country_title_en,
                        ],
                        'state' => [
                            'id' => $airport->state_id,
                            'title_fa' => $airport->state_title_fa,
                            'title_en' => $airport->state_title_en,
                        ],
                        'city' => [
                            'id' => $airport->city_id,
                            'title_fa' => $airport->city_title_fa,
                            'title_en' => $airport->city_title_en,
                        ],
                        'priority' => $airport->priority,
                        'status' => $airport->status,
                    ];
                    Redis::set('airports:' . $data, json_encode($airport));
                }

                return $airport;
            } elseif ($action == 'supplier') {
                if ($branch != 2) {
                    $supplierApi = DB::table('mapping_colleagues')
                        ->select('colleague')
                        ->where('airplus', $data)
                        ->where('status', 1)
                        ->first();
                    if (is_null($supplierApi)) {
                        $supplierApi = (object) ['colleague' => 1];
                    }
                } else {
                    $supplierApi = (object) ['colleague' => $airline ? $airline['object'] : 1];
                }
                $supplier = json_decode(Redis::get('colleagues:' . $supplierApi->colleague), true);
                if (! $supplier) {
                    $supplier = DB::table('colleagues')->select('id', 'office as title_fa', 'office as title_en', 'first_name', 'last_name', 'credit_amount', 'status')->where('id', $supplierApi->colleague)->first();
                    if (is_null($supplier)) {
                        $supplier = [
                            'id' => $data,
                            'title_fa' => $data,
                            'title_en' => $data,
                            'first_name' => $data,
                            'last_name' => $data,
                            'credit_amount' => $data,
                            'status' => $data,
                        ];
                    } else {
                        Redis::set('colleagues:' . $supplierApi->colleague, json_encode($supplier));
                    }
                }

                return $supplier;
            } elseif ($action == 'system_supplier') {
                $supplierApi = DB::table('mapping_colleagues')
                    ->select('colleague')
                    ->where('airplus', $data)
                    ->where('status', 1)
                    ->first();

                if (is_null($supplierApi)) {
                    $supplierApi = (object) ['colleague' => 0];
                }
                $supplier = json_decode(Redis::get('colleagues:' . $supplierApi->colleague), true);
                if (! $supplier) {
                    $supplier = DB::table('colleagues')->select('id', 'office as title_fa', 'office as title_en', 'first_name', 'last_name', 'credit_amount', 'status')->where('id', $supplierApi->colleague)->first();
                    if (is_null($supplier)) {
                        $supplier = [
                            'id' => $data,
                            'title_fa' => $data,
                            'title_en' => $data,
                            'first_name' => $data,
                            'last_name' => $data,
                            'credit_amount' => $data,
                            'status' => $data,
                        ];
                    } else {
                        Redis::set('colleagues:' . $supplierApi->colleague, json_encode($supplier));
                    }
                }

                return $supplier;
            }
        }
    }


    static function getClassName($class)
    {
        switch ($class) {
            case 'S':
                $tempClass = 'Economy/Coach';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'Y':
                $tempClass = 'Economy/Coach';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'B':
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'H':
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'K':
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'L':
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'M':
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'N':
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'V':
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'Q':
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'O':
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'J':
                $tempClass = 'Business Class Premium';
                $tempClassTitleFa = 'بیزینس کلاس';
                break;
            case 'C':
                $tempClass = 'Business Class';
                $tempClassTitleFa = 'بیزینس کلاس';
                break;
            case 'D':
                $tempClass = 'Business Class Discounted';
                $tempClassTitleFa = 'بیزینس کلاس';
                break;
            case 'I':
                $tempClass = 'Business Class Discounted';
                $tempClassTitleFa = 'بیزینس کلاس';
                break;
            case 'Z':
                $tempClass = 'Business Class Discounted';
                $tempClassTitleFa = 'بیزینس کلاس';
                break;
            case 'CP':
                $tempClass = 'Business Class Discounted';
                $tempClassTitleFa = 'بیزینس کلاس';
                break;
            case 'R':
                $tempClass = 'Supersonic or First Class Suit';
                $tempClassTitleFa = 'فرست کلاس';
                break;
            case 'P':
                $tempClass = 'First Class Premium';
                $tempClassTitleFa = 'فرست کلاس';
                break;
            case 'F':
                $tempClass = 'First Class';
                $tempClassTitleFa = 'فرست کلاس';
                break;
            case 'A':
                $tempClass = 'First Class Discounted';
                $tempClassTitleFa = 'فرست کلاس';
                break;
            case 'X':
                $tempClass = 'Free economy ticket';
                $tempClassTitleFa = 'اکونومی';
                break;
            case 'I':
                $tempClass = 'Free commercial ticket';
                $tempClassTitleFa = 'فرست کلاس';
                break;
            case 'O':
                $tempClass = 'Free first class ticket';
                $tempClassTitleFa = 'فرست کلاس';
                break;
            default:
                $tempClass = $class;
                $tempClassTitleFa = 'اکونومی';
                break;
        }
        return [
            "iata" => $class,
            "title" => [
                "fa" => $tempClassTitleFa,
                "en" => $tempClass
            ]
        ];
    }
}
