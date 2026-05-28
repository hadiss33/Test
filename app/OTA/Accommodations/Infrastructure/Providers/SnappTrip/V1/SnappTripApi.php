<?php

namespace App\Services\OTA\Accommodations\Infrastructure\Providers\SnappTrip\V1;

use App\Lib\Auxiliary\Visa;
use App\Lib\BaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class SnappTripApi
{
    function sendRequestAccommodation($request, array $pathParams = [], array $data = [], $branch = null, $method = 'GET')
    {
        $apiEndpoints = [
            // cities
            'get_cities' => '/cities',
            'get_city_hotels' => '/cities/{city_id}/hotels',

            // hotels
            'get_hotel_details' => '/hotels',
            'get_hotel_facilities' => '/hotels/facilities',
            'get_hotel_galleries' => '/hotels/galleries',
            'get_hotel_reviews' => '/hotels/reviews',
            'get_hotel_rooms' => '/hotels/rooms',

            // availability
            'get_cities_availability' => '/availability/cities',
            'get_hotels_availability' => '/availability/hotels',
            'get_hotel_availability' => '/availability/hotels/{hotel_id}',
            'get_hotel_availability_calendar' => '/availability/hotels/{hotel_id}/calendar',
            'get_room_availability_calendar' => '/availability/hotels/{hotel_id}/room/{hotel_id}/calendar',

            // booking
            'create_book' => '/booking/create',
            'get_book' => '/booking/{book_code}',
            'lock_book' => '/booking/{book_code}/lock',
            'confirm_book' => '/booking/{book_code}/confirm',
        ];

        $query = DB::table('application_interface');
        $query->where('status', 1);
        $query->where('type', 'api');
        if (!is_null($branch)) {
            $query->where('branch', $branch);
        } else {
            $query->where('branch', 1);
        }
        $query->where('service', 'snapptrip_hotel');
        $snappTripInterface = $query->first();

        // اگر نتیجه‌ای پیدا نشد و branch مشخص شده بود، branch = 1 را امتحان کن
        if (!$snappTripInterface && !is_null($branch)) {
            $query = DB::table('application_interface');
            $query->where('status', 1);
            $query->where('type', 'api');
            $query->where('branch', 1);
            $query->where('service', 'snapptrip_hotel');
            $snappTripInterface = $query->first();
        }

        if (!$snappTripInterface) {
            return response()->json([
                "status" => false,
                "time" => time(),
                "error" => [
                    "code" => 1000,
                    "message" => "SnappTrip interface not found.",
                ],
                "support" => [
                    "phone" => "021-91016838 in 121",
                    "email" => ""
                ]
            ], 404);
        }

        switch ($request) {
            case 'get_cities_availability':
            case 'create_book':
            case 'lock_book':
            case 'confirm_book':
                $method = 'POST';
                break;
        }

        $url = $snappTripInterface->url;
        $authData = json_decode($snappTripInterface->data, true);

        $param = [];
        foreach ($data as $key => $value) $param[$key] = $value;

        $requestUrl = $url . $apiEndpoints[$request];
        foreach ($pathParams as $key => $value) {
            $requestUrl = str_replace('{' . $key . '}', $value, $requestUrl);
        }
        if ($method == 'POST') {
            try {
                $response = Http::timeout(20)
                    ->withHeaders([
                        'api-key' => $authData['api_key'],
                    ])
                    ->post($requestUrl, $param);
            } catch (\Exception $e) {
                Visa::addSystemReport($e->getMessage());
                if ($request == 'confirm_book') {
                    $bookCode = $param['book_code'] ?? 'unknown';
                    $redisKey = "booking_retry_{$bookCode}";

                    $attempts = Redis::connection('demo')->get($redisKey) ?? 0;

                    if ($attempts >= 2) {
                        Redis::connection('demo')->del($redisKey);
                        return [
                            'Status' => false,
                            'Time' => time(),
                            'Information' => [
                                'success' => false,
                                'message' => 'از سمت تامین کننده خطایی رخ داده است، لطفا قبل از خرید مجدد با واحد فنی هماهنگ شوید.'
                            ]
                        ];
                    }

                    Redis::connection('demo')->setex($redisKey, 300, $attempts + 1);

                    $checkStatus = BaseService::getAccommodationStatus(count($param) > 0 ? $param : $pathParams, 'snapptrip', $branch);
                    if ((isset($checkStatus['Data']['reservation_code']) && $checkStatus['Data']['reservation_code']) && $checkStatus['Data']['state'] == 'confirmed') {
                        Redis::connection('demo')->del($redisKey);
                        return SnappTripApi::accommodationResultAPI2ResultSys(
                            'accommodation',
                            $request,
                            $checkStatus['Data']
                        );
                    }
                }
                return [
                    'Status' => false,
                    'Time' => time(),
                    'Information' => [
                        'success' => false,
                        'message' => 'از سمت تامین کننده خطایی رخ داده است، لطفا قبل از خرید مجدد با واحد فنی هماهنگ شوید.'
                    ]
                ];
            }
            return SnappTripApi::accommodationResultAPI2ResultSys(
                'accommodation',
                $request,
                $response->json()
            );
        } elseif ($method == 'GET') {
            return SnappTripApi::accommodationResultAPI2ResultSys(
                'accommodation',
                $request,
                Http::withHeaders([
                    'api-key' => $authData['api_key'],
                ])->get($requestUrl, $param)->throw()->json()
            );
        }
    }

    static function accommodationResultAPI2ResultSys($goal, $method, $data, $subdata = null, $details = false, $branch = false)
    {
        if ($method == 'create_book' || $method == 'confirm_book') {
            Visa::addSystemReport($data);
        }
        if ($goal == 'accommodation') {
            if (isset($data['success']) && !$data['success']) {
                Visa::addSystemReport($data);
                return [
                    'Status' => false,
                    'Time' => time(),
                    'Information' => $data
                ];
            } else {
                return [
                    'Status' => true,
                    'Time' => time(),
                    'Information' => $data
                ];
            }
        }
    }
}
