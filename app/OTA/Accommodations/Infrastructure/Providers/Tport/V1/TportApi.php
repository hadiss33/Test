<?php

namespace App\Services\OTA\Accommodations\Infrastructure\Providers\Tport\V1;

use App\Lib\Auxiliary\Visa;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TportApi
{
    function sendRequestAccommodation(string $action, array $data = [], $method = 'POST')
    {
        $apiEndpoints = [
            // Hotel
            'get_list_of_hotel_type_fa' => '/hotel/unAuthorized/hotelType',
            'get_hotel_list' => '/hotel/list',
            'get_hotel_room_list' => '/hotel/findListOfHotelAndRoom/getHotelRoomList',
            'get_desc_and_address' => '/hotel/selectedHotel/get',
            'get_hotel_facility' => '/hotel/selectedHotel/facility',
            'get_hotel_picture_list' => '/hotel/hotelPicture/getHotelPictureList',
            'get_hotel_distance' => '/hotel/attraction/get',
            'get_cancellation_rule' => '/hotel/cancellationRule/get',
            'get_hotel_rule' => '/hotel/hotelRule/get',
            'get_hotel_room_price' => '/hotel/selectedHotel/getHotelRoomPrice',

            // RoomType
            'get_room_type_list' => '/hotel/list',

            // FoodService
            'get_food_service_type' => '/hotel/list',

            // Facility
            'get_hotel_facility_type' => '/hotel/list',

            // ConvertImageIdToPicture
            'convert_image_id_to_picture' => '/hotel/picture/convert',

            // Province And City
            'get_province_list' => '/hotel/unAuthorized/getProvince',
            'get_city_list' => '/hotel/unAuthorized/getCity',
            'get_province_city_tree_with_url' => '/hotel/search/allCityProvince',

            // Reserve
            'save_normal_reserve' => '/hotel/normalReserve/saveNormalReserve',
            'calculate_price' => '/hotel/getRoomSumPrice/calculatePrice',
            'cancel_reserve_request' => '/hotel/reserve/cancelReserveRequest',
            'final_reservation' => '/hotel/agencyPay/token',
            'get_reserve_list' => '/hotel/list',
        ];

        $tportData = DB::table('application_interface')->where('status', 1)->where('type', 'api')->where('service', 'tport')->first();
        if (!$tportData) {
            return [
                "status" => false,
                "time" => time(),
                "error" => [
                    "code" => 1000,
                    "message" => "Tport interface not found.",
                ],
                "support" => [
                    "phone" => "021-91016838 in 121",
                    "email" => ""
                ]
            ];
        }

        $url = $tportData->url;
        $authData = json_decode($tportData->data, true);
        $parsedDate = Carbon::parse($authData['expires_in']);
        if (now()->timestamp > $parsedDate->timestamp) {
            $authData = TportApi::login($tportData);
        }

        $param = [
            'sort' => null,
            'filter' => new \stdClass(),
            'data' => new \stdClass(),
            'providerQualifier' => '',
            'aggregate' => [],
            'group' => [],
            'skip' => 0,
            'take' => 100,
        ];
        switch ($action) {
            case 'get_list_of_hotel_type_fa':
            case 'get_province_list':
                $param['providerQualifier'] = TportApi::convertToCamelCase($action) . 'GridProvider';
                break;

            case 'get_hotel_list':
                $param['providerQualifier'] = TportApi::convertToCamelCase($action) . 'GridProvider';
                $param['filter'] = [
                    'field' => null,
                    'joinType' => 'INNER',
                    'logic' => 'and',
                    'filters' => [],
                    'value' => null,
                    'operator' => null,
                    'ignoreCase' => false,
                    'truncateDate' => true,
                    'disable' => false
                ];
                if (isset($data['city_id']) and $data['city_id']) {
                    $param['filter']['filters'][] = [
                        'field' => 'cityId',
                        'operator' => 'eq',
                        'value' => $data['city_id']
                    ];
                }
                if (isset($data['hotel_id']) and $data['hotel_id']) {
                    $param['filter']['filters'][] = [
                        'field' => 'hotelId',
                        'operator' => 'eq',
                        'value' => $data['hotel_id']
                    ];
                }
                break;

            case 'get_hotel_room_list':
                $param['data'] = [
                    'agencyId' => $authData['agency_id'],
                    'hotelId' => null
                ];
                $param['filter'] = [
                    'field' => null,
                    'joinType' => 'INNER',
                    'logic' => 'and',
                    'filters' => [
                        [
                            'field' => 'hotelId',
                            'operator' => 'eq',
                            'value' => $data['hotel_id']
                        ]
                    ],
                    'value' => null,
                    'operator' => null,
                    'ignoreCase' => false,
                    'truncateDate' => true,
                    'disable' => false
                ];
                break;

            case 'get_desc_and_address':
            case 'get_hotel_facility':
                $param = [
                    'hotelId' => $data['hotel_id'],
                    'languageId' => 1,
                    'agencyId' => $authData['agency_id'],
                    'hotelUrl' => null
                ];
                if (isset($data['room_type_id']) and $data['room_type_id']) {
                    $param['roomTypeId'] = $data['room_type_id'];
                }
                break;

            case 'get_hotel_picture_list':
                $param['data'] = [
                    'pictureConsumerId' => 1,
                    'pictureSizeGroupId' => 1,
                    'languageId' => 1,
                    'hotelId' => $data['hotel_id'],
                    'roomTypeId' => null
                ];
                if (isset($data['room_type_id']) and $data['room_type_id']) {
                    $param['data']['roomTypeId'] = $data['room_type_id'];
                }
                $param['filter'] = [
                    'field' => null,
                    'joinType' => 'INNER',
                    'logic' => 'and',
                    'filters' => [],
                    'value' => null,
                    'operator' => null,
                    'ignoreCase' => false,
                    'truncateDate' => true,
                    'disable' => false
                ];
                break;

            case 'get_hotel_distance':
            case 'get_hotel_rule':
                $param = [
                    'hotelId' => $data['hotel_id'],
                    'languageId' => 1,
                    'hotelUrl' => null
                ];
                break;

            case 'get_cancellation_rule':
                $param['data'] = [
                    'hotelId' => $data['hotel_id'],
                    'registerRequestId' => null
                ];
                $param['providerQualifier'] = 'string';
                break;

            case 'get_hotel_room_price':
                $param = [
                    'hotelId' => $data['hotel_id'],
                    'agencyId' => $authData['agency_id'],
                    'roomTypeId' => null,
                    'guarantorId' => null,
                    'startDate' => $data['start_date'],
                    'endDate' => $data['end_date'],
                    'foodServiceType' => null,
                    'currencyFlag' => 0
                ];
                if (isset($data['room_type_id']) and $data['room_type_id']) {
                    $param['roomTypeId'] = $data['room_type_id'];
                }
                break;

            case 'get_city_list':
                if (isset($data['province_id']) and $data['province_id']) {
                    $param['data'] = [
                        'provinceId' => $data['province_id'],
                    ];
                } else {
                    $param['data'] = [
                        'provinceId' => null,
                    ];
                }
                break;

            case 'convert_image_id_to_picture':
                $apiEndpoints[$action] = '/hotel/picture/convert/' . $data['image_id'];
                break;

            case 'get_hotel_facility_type':
                $param['take'] = 200;
                $param['providerQualifier'] = TportApi::convertToCamelCase($action) . 'GridProvider';
                break;

            case 'save_normal_reserve':
                $param = [
                    'agencyId' => $authData['agency_id'],
                    'guestProfileId' => null,
                    'hotelId' => $data['hotel_id'],
                    'startDate' => $data['start_date'],
                    'endDate' => $data['end_date'],
                    'guarantorId' => null,
                    'currencyFlag' => 0,
                    'roomList' => $data['room_list'],
                    'startTime' => '14:00',
                    'endTime' => '12:00'
                ];

                break;

            case 'calculate_price':
                $param = [
                    'hotelId' => $data['hotel_id'],
                    'agencyId' => $authData['agency_id'],
                    'guarantorId' => null,
                    'currencyFlag' => 0,
                    'foodServiceTypeIdAndRoomTypeIdReqList' => $data['room_list']
                ];
                break;

            case 'cancel_reserve_request':
                $param = [
                    'hotelId' => $data['hotel_id'],
                    'agencyId' => $authData['agency_id'],
                    'guarantorId' => null,
                    'reserveId' => $data['reserve_id'],
                    'guestProfileId' => null,
                    'reserveRoomId' => $data['reserve_room_id'],
                    'guestDescription' => $data['guest_description'],
                    'agencyDescription' => $data['agency_description']
                ];
                break;

            case 'final_reservation':
                $param = [
                    'ipgProviderId' => 0,
                    'agencyId' => $authData['agency_id'],
                    'paymentRequestList' => [
                        [
                            'amount' => $data['amount'],
                            'reserveId' => $data['reserve_id']
                        ]
                    ],
                    'failedRedirectUrl' => 'string',
                    'successRedirectUrl' => 'string',
                    'transactionNumber' => 'string',
                    'description' => 'string',
                    'bankAccountId' => 0,
                    'imageId' => 'string',
                    'payDate' => now()->toIso8601String(),
                    'paymentTypeId' => 7,
                    'username' => 'string',
                    'appCode' => 'string',
                    'fileName' => 'string',
                    'fileContentType' => 'string',
                    'fileData' => 'string'
                ];
                break;

            case 'get_reserve_list':
                $param['providerQualifier'] = 'reserveRequestGridProvider';
                $param['data'] = [
                    'agencyId' => $authData['agency_id'],
                    'roomGuest' => null
                ];
                if (isset($data['reserve_id']) and $data['reserve_id']) {
                    $param['filter'] = [
                        'field' => null,
                        'joinType' => 'INNER',
                        'logic' => 'and',
                        'filters' => [
                            [
                                'filters' => [
                                    [
                                        'field' => 'reserveId',
                                        'joinType' => 'INNER',
                                        'logic' => 'and',
                                        'filters' => [],
                                        'value' => $data['reserve_id'],
                                        'operator' => 'eq',
                                        'ignoreCase' => false,
                                        'truncateDate' => true,
                                        'disable' => false
                                    ]
                                ],
                                'logic' => 'and'
                            ]
                        ],
                        'value' => null,
                        'operator' => null,
                        'ignoreCase' => false,
                        'truncateDate' => true,
                        'disable' => false
                    ];
                }
                break;
        }

        try {
            if ($method == 'POST') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $authData['access_token'],
                ])->post($url . $apiEndpoints[$action], $param)->json();
            } elseif ($method == 'GET' and $action == 'convert_image_id_to_picture') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $authData['access_token'],
                ])->get($url . $apiEndpoints[$action], $param);
            } elseif ($method == 'GET') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $authData['access_token'],
                ])->get($url . $apiEndpoints[$action], $param)->json();
            }

            if ($action == 'final_reservation' || $action == 'get_reserve_list' || $action == 'save_normal_reserve' || $action == 'calculate_price') {
                Visa::addSystemReport($response);
            }
            return $this->accommodationResultAPI2ResultSys($action, $response);
        } catch (\Exception $e) {
            Visa::addSystemReport($e->getTrace());
            return [
                'Status' => false,
                'Time' => time(),
                "message" => $e->getMessage(),
                "trace" => $e->getTrace(),
                'Information' => $e->getMessage(),
            ];
        }
    }

    function convertToCamelCase(string $string)
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $string))));
    }

    function login(object $data)
    {
        $apiEndpoints = [
            // Login
            'token' => '/ota-auth/oauth/token',
            'authenticated_user_info' => '/sec/user/authenticatedUserInfo',
            'applications_of_user' => 'sec/application/applicationsOfUser',
            'organizations_of_user' => 'sec/organization/organizationsOfUser',
        ];

        $url = $data->url;

        $authParam = ['grant_type' => 'password', 'username' => $data->username, 'password' => $data->password];
        $response = Http::asMultipart()
            ->withBasicAuth('OtaWSClientId', 'test')
            ->post($url . $apiEndpoints['token'], $authParam)->throw();

        if ($response->successful()) {
            $response = $response->json();
            $authData = [
                'access_token' => $response['access_token'],
                'agency_id' => 53690364,
                'expires_in' => $response['expires_in']
            ];
            DB::table('application_interface')
                ->where('id', $data->id)
                ->update(['data' => json_encode($authData, true)]);

            return $authData;
        } else {
            return [
                "status" => false,
                "time" => time(),
                "error" => [
                    "code" => 1001,
                    "message" => "Authorization failed.",
                ],
                "support" => [
                    "phone" => "021-91016838 in 121",
                    "email" => "ict@airplus.app",
                    "panel" => "helpdesk.airplus.app"
                ],
            ];
        }
    }

    private function accommodationResultAPI2ResultSys(string $method, array $data)
    {
        if ($method == 'get_hotel_list' or $method == 'get_reserve_list') {
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data['data']
            ];
        } else if ($method == 'get_hotel_room_list') {
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data['result']['data']
            ];
        } else if ($method == 'get_hotel_room_price') {
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data['result']
            ];
        } else if ($method == 'get_city_list') {
            $cities = DB::table('cities')->get();
            $systemCityMap = [];
            foreach ($cities as $systemCity) {
                $systemCityMap[$systemCity->fa_name] = $systemCity->id;
            }

            $unmappedCities = [];
            foreach ($data['result']['data'] as $city) {
                $mappingCities = DB::table('mapping_cities')->where('tport', $city['cityId'])->get();
                if ($mappingCities->isEmpty()) {
                    if (isset($systemCityMap[$city['cityName']])) {
                        DB::table('mapping_cities')->insert([
                            'tport' => $city['cityId'],
                            'city' => $systemCityMap[$city['cityName']],
                        ]);
                    } else {
                        $unmappedCities[] = [
                            'tportCityName' => $city['cityName'],
                            'tportCityId' => $city['cityId'],
                        ];
                    }
                }
            }
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $unmappedCities
            ];
        } else if ($method == 'get_hotel_picture_list') {
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data['result']
            ];
        } else if ($method == 'convert_image_id_to_picture') {
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data['result']
                'Information' => $data['result']
            ];
        } else if ($method == 'get_hotel_facility_type') {
            $data = collect($data['data']);
            $categories = $data->unique('facilityGroupId');
            foreach ($categories as $category) {
                $mappedCategory = DB::table('mapping_facility_categories')->where('tport', $category['facilityGroupId'])->first();
                if (!$mappedCategory) {
                    $insertedCategoryId = DB::table('facilities_categories')->insertGetId([
                        'title_fa' => $category['facilityGroupName'],
                        'branch' => request()->get('branch'),
                    ]);

                    DB::table('mapping_facility_categories')->insertGetId([
                        'facility_category' => $insertedCategoryId,
                        'tport' => $category['facilityGroupId'],
                    ]);
                }
            }

            foreach ($data as $facility) {
                $mappedFacility = DB::table('mapping_facilities')->where('tport', $facility['facilityId'])->first();
                if (!$mappedFacility) {
                    $mappedCategory = DB::table('mapping_facility_categories')->where('tport', $facility['facilityGroupId'])->first();
                    $insertedFacilityId = DB::table('facilities')->insertGetId([
                        'category_id' => $mappedCategory->facility_category,
                        'title_fa' => $facility['facilityName'],
                        'branch' => request()->get('branch'),
                    ]);

                    DB::table('mapping_facilities')->insertGetId([
                        'facility' => $insertedFacilityId,
                        'tport' => $facility['facilityId'],
                    ]);
                }
            }

            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data
            ];
        } else if ($method == 'get_hotel_facility' or $method == 'get_desc_and_address' or $method == 'get_hotel_rule' or $method == 'save_normal_reserve' or $method == 'calculate_price' or $method == 'final_reservation' or $method == 'get_cancellation_rule') {
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data['result']
            ];
        } else if ($method == 'cancel_reserve_request') {
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data
            ];
        }
    }
}
