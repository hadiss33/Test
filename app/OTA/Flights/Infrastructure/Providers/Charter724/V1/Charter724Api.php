<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Charter724\V1;

use App\Http\Controllers\Api\Panel\V2\StaticController;
use App\Http\Controllers\Api\Reservation\V1\ReservationController;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class Charter724Api
{
    public static $isHub = false;
    public static $isSalesChannels = false;

    static function sendRequest($request, array $data = [], $type = 'flight', $branch = false)
    {
        if (strpos($branch, 'b2c-') !== false) {
            $branch = str_replace('b2c-', '', $branch);
            self::$isSalesChannels = true;
        } else if (strpos($branch, 'b2b-') !== false) {
            $branch = str_replace('b2b-', '', $branch);
            self::$isSalesChannels = true;
        }

        $interface = DB::table('application_interface')
            ->where('branch', $branch)
            ->where('status', 1)
            ->where('type', 'api')
            ->where('service', 'charter724')
            ->first();
        if (!$interface) {
            $tempBranch = DB::table('offices')->select('type', 'base_online')->where('id', $branch)->first();
            if (!is_null($tempBranch->base_online) && $tempBranch->base_online == 1) {
                $interface = DB::table('application_interface')
                    ->where('branch', 1)
                    ->where('status', 1)
                    ->where('type', 'api')
                    ->where('service', 'charter724')
                    ->first();
                if (!$interface) {
                    return [
                        "Data" => [
                            'Status' => false,
                            'Code' => '2007',
                            'Message' => ['Information' => 'Api Not Found!']
                        ]
                    ];
                }
                self::$isHub = true;
            } else {
                return [
                    "Data" => [
                        'Status' => false,
                        'Code' => '2007',
                        'Message' => ['Information' => 'Api Not Found!']
                    ]
                ];
            }
        }
        if ($interface) {
            $authData = json_decode($interface->data, true);
            $parsedDate = Carbon::parse($authData['expires_in']);
            if (now()->timestamp > $parsedDate->timestamp) {
                $authData = Charter724Api::getToken($interface);
            }
            if ($request == 'available') {
                $endPoint = '/WebService/Available';
                $param = [
                    'from_flight' => $data['flight_from'],
                    'to_flight' => $data['flight_to'],
                    'date_flight' => $data['flight_date'],
                ];
            } else if ($request == 'available15Days') {
                $endPoint = '/WebService/Available15Days';
                $param = [
                    'from_flight' => $data['flight_from'],
                    'to_flight' => $data['flight_to'],
                ];
            } else if ($request == 'getCharge') {
                $endPoint = '/WebService/GetCharge';
            } else if ($request == 'getCaptcha') {
                $endPoint = '/WebService/GetCaptcha';
                $param = [
                    'from_flight' => $data['flight_from'],
                    'to_flight' => $data['flight_to'],
                    'date_flight' => $data['flight_date'],
                    'time_flight' => $data['flight_time'],
                    'number_flight' => $data['flight_number'],
                    'ajency_online_ID' => $data['agency_id'],
                    'cabinclass' => $data['class'],
                    'sellingType' => $data['sale_type'],
                    'airline' => $data['airline'],
                ];
            } else if ($request == 'reservation') {
                $endPoint = '/WebService/Reservation';
                $passengers = [];
                foreach ($data['passengers'] as $passenger) {
                    $passengers[] = [
                        'passengerType' => $passenger['type'],
                        'fnamefa' => $passenger['first_name_fa'],
                        'lnamefa' => $passenger['last_name_fa'],
                        'fnameen' => $passenger['first_name_en'],
                        'lnameen' => $passenger['last_name_en'],
                        'gender' => $passenger['gender'],
                        'nationality' => $passenger['nationality'],
                        'passengerCode' => $passenger['code'],
                        'nationalitycode' => $passenger['nationality_code'],
                        'expdate' => $passenger['passport_expire_date'],
                        'birthday' => $passenger['birthday'],
                    ];
                }
                $param = [
                    'id_request' => $data['request_id'],
                    'captchcode' => $data['captcha_code'],
                    'countryMobileCode' => $data['country_mobile_code'],
                    'mobile' => $data['mobile'],
                    'email' => $data['email'],
                    'passengers' => $passengers,
                ];
            } else if ($request == 'buyTicket') {
                $endPoint = '/WebService/BuyTicket';
                $param = [
                    'id_request' => $data['request_id'],
                    'id_faktor' => $data['factor_id'],
                ];
            } else if ($request == 'payAndBuyTicket') {
                $endPoint = '/WebService/PayAndBuyTicket';
                $param = [
                    'id_request' => $data['request_id'],
                    'id_faktor' => $data['factor_id'],
                    'price' => $data['price'],
                    'redirectURL' => $data['redirect_url'],
                ];
            } else if ($request == 'checkTransaction') {
                $endPoint = '/WebService/CheckTransaction';
                $param = [
                    'id_request' => $data['request_id'],
                    'id_faktor' => $data['factor_id'],
                ];
            } else if ($request == 'checkPenalty') {
                $endPoint = '/WebService/CheckPenalti';
                $param = [
                    'id_faktor' => $data['factor_id'],
                ];
            } else if ($request == 'cancelTicket') {
                $endPoint = '/WebService/CancelTicket';
                $param = [
                    'id_faktor' => $data['factor_id'],
                    'penalti' => $data['penalty'],
                    'listticketID' => $data['ticket_list'],
                ];
            }

            if ($request == 'getCharge') {
                $result = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $authData['access_token'],
                ])->get($interface->url . $endPoint)->throw()->json();
            } else {
                $result = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $authData['access_token'],
                ])->post($interface->url . $endPoint, $param)->throw()->json();
            }

            try {
                if ($result && $result['result']) {
                    return Charter724Api::resultAPI2ResultSys($request, $result, $data, $branch, $interface);
                } else {
                    return [
                        "Data" => [[
                            'Status' => false,
                            'Code' => '2001',
                            'Message' => $result['msg'] . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                            'Trace' => $result
                        ]],
                    ];
                }
            } catch (Exception $e) {
                return [
                    "Data" => [[
                        'Status' => false,
                        'Code' => '2000-' . $e->getCode(),
                        'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                        'Trace' => $e->getTrace()
                    ]],
                ];
            }
        } else {
            return [
                "Data" => [
                    'Status' => false,
                    'Code' => '2007',
                    'Message' => ['Information' => 'Api Not Found!']
                ]
            ];
        }
    }

    static function getToken($data)
    {
        $authParam = ['username' => $data->username, 'password' => $data->password];
        $base64Response = Http::post($data->url . '/userPassBase64', $authParam)->throw();

        if ($base64Response->successful()) {
            $base64Response = $base64Response->json();
            $tokenResponse = Http::post($data->url . '/Login', ['userPassBase64' => $base64Response['data']])->throw();
            if ($tokenResponse->successful()) {
                $tokenResponse = $tokenResponse->json();
                $authData = [
                    'access_token' => $tokenResponse['data']['access_token'],
                    'expires_in' => $tokenResponse['data']['expires_Utc'],
                    'token_type' => $tokenResponse['data']['token_type'],
                ];
                DB::table('application_interface')
                    ->where('id', $data->id)
                    ->update(['data' => json_encode($authData, true)]);

                return $authData;
            } else {
                return response()->json([
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
                ], $tokenResponse->status());
            }
        } else {
            return response()->json([
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
            ], $base64Response->status());
        }
    }

    static function resultAPI2ResultSys($method, $data, $subData = false, $branch = false, $serviceDetails = false)
    {
        if (($method == 'available' || $method == 'available15Days') && $data && $data['data']) {
            $flights = [];
            foreach ($data['data'] as $flight) {
                $tempFinancial = [
                    'PriceAdditions' => [
                        'Citizens' => false,
                    ],
                    'CommissionPaid' => [
                        'Percentage' => false,
                        'Transaction' => false,
                        'MembershipRight' => false,
                    ],
                    'Adult' => [
                        'BaseFare' => isset($flight['price_final_fare']) ? $flight['price_final_fare'] : false,
                        'Tax' => false,
                        'Markup' => isset($flight['price_Markup']) ? $flight['price_Markup'] : false,
                        'TotalFare' => false,
                        'Payable' => isset($flight['price_final']) ? $flight['price_final'] : false,
                        'Commission' => [
                            'Percentage' => false,
                            'Final' => false,
                            'Price' => false
                        ]
                    ],
                    'Child' => [
                        'BaseFare' => isset($flight['price_final_chd_fare']) ? $flight['price_final_chd_fare'] : false,
                        'Tax' => false,
                        'Markup' => isset($flight['price_Markup']) ? $flight['price_Markup'] : false,
                        'TotalFare' => false,
                        'Payable' => isset($flight['price_final_chd']) ? $flight['price_final_chd'] : false,
                        'Commission' => [
                            'Percentage' => false,
                            'Final' => false,
                            'Price' => false
                        ]
                    ],
                    'Infant' => [
                        'BaseFare' => isset($flight['price_final_inf_fare']) ? $flight['price_final_inf_fare'] : false,
                        'Tax' => false,
                        'Markup' => isset($flight['price_Markup']) ? $flight['price_Markup'] : false,
                        'TotalFare' => false,
                        'Payable' => isset($flight['price_final_inf']) ? $flight['price_final_inf'] : false,
                        'Commission' => [
                            'Percentage' => false,
                            'Final' => false,
                            'Price' => false
                        ]
                    ]
                ];
                $class = [
                    'FlightStatus' => true,
                    'Reservable' => true,
                    'Status' => 'reservable',
                    'CancelationPolicy' => false,
                    'BookingPolicy' => false,
                    'Supplier' => false,
                    'SystemSupplier' => false,
                    'FlightId' => false,
                    'FareName' => false,
                    'CabinType' => Charter724Api::getClassName($flight['cabinclass']),
                    'AvailableSeat' => $flight['capacity'],
                    'Rules' => false,
                    'Financial' => $tempFinancial,
                    'BaseData' => [
                        "Supplier" => [
                            'Supplier' => false,
                            'SystemSupplier' => false
                        ],
                        'Financial' => $tempFinancial
                    ],
                    'Baggage' => false
                ];
                $foundIndex = null;
                if ($flights) {
                    foreach ($flights as $index => $existingFlight) {
                        if ($existingFlight['FlightNumber'] == $flight['number_flight']) {
                            $foundIndex = $index;
                            break;
                        }
                    }
                }
                if ($foundIndex !== null) {
                    $flights[$foundIndex]['Classes'][] = $class;
                } else {
                    $flights[] = [
                        'Service' => 'charter724',
                        'ServiceId' => is_object($serviceDetails) ? $serviceDetails->id : false,
                        'ServiceBranch' => is_object($serviceDetails) ? $serviceDetails->branch : false,
                        'DisplayableService' => self::$isHub ? 'airplusHub' : 'charter724',
                        'Verified' => true,
                        'FlightType' => ucfirst($flight['type']),
                        'FlightRoute' => $flight['is_international'] ? 'International' : 'Internal',
                        'FlightNumber' => $flight['number_flight'],
                        'Origin' => [
                            'Iata' => Charter724Api::getDetails('airport', $flight['from'], true),
                            'Terminal' => false
                        ],
                        'Destination' => [
                            'Iata' => Charter724Api::getDetails('airport', $flight['to'], true),
                            'Terminal' => false
                        ],
                        'DepartureDateTime' => $flight['date_flight'] . ' ' . $flight['time_flight'],
                        'ArrivalDateTime' => false,
                        'Duration' => false,
                        'Aircraft' => Charter724Api::getDetails('aircraft', $flight['carrier'], true),
                        'Airline' => Charter724Api::getDetails('airline', $flight['airline'], true),
                        'Remarks' => [
                            'AirlineSupplier' => false,
                            'TourRequirement' => false,
                            'OneWayRequirement' => false,
                            'RoundtripRequirement' => false,
                            'PhoneRequirement' => false,
                            'FlightReturn' => false,
                            'DateReturn' => false,
                            'Description' => false,
                            'Special' => false,
                            'Warranty' => false,
                        ],
                        'ReturningFlight' => false,
                        'Steps' => false,
                        'Classes' => [$class]
                    ];
                }
            }
            if ($flights) {
                $arr = [
                    'Status' => true,
                    'Time' => time(),
                    'CurrencyCode' => 'IRR',
                    'Information' => $flights
                ];
            } else {
                $arr = [
                    'Status' => false,
                    'Time' => time(),
                    'Code' => '1503',
                    'Message' => $flight
                ];
            }
            return ["Data" => $arr, 'Result' => $data];
        } else if ($method == 'getCharge' && $data) {
            return $data['data'];
        } else if ($method == 'getCaptcha' && $data) {
            return [
                'request_id' => $data['data']['id_request'],
                'captcha_link' => $data['data']['link_captcha']
            ];
        } else if ($method == 'reservation' && $data) {
            return [
                'request_id' => $data['data']['id_request'],
                'factor_id' => $data['data']['id_faktor'],
                'total_price' => $data['data']['totalprice_request'],
                'passengers' => $data['data']['passenger_info']
            ];
        } else if ($method == 'buyTicket' && $data) {
            return [
                'request_id' => $data['data']['id_request'],
                'factor_id' => $data['data']['id_faktor'],
                'pnr_code' => $data['data']['pnrid_request'],
                'ticket_link' => $data['data']['linkticket'],
                'ref_bank' => $data['data']['bank_Refnum'],
                'trace_bank' => $data['data']['bank_TraceNo'],
                'passengers' => $data['data']['passenger_info']
            ];
        } else if ($method == 'payAndBuyTicket' && $data) {
            return [
                'request_id' => $data['data']['id_request'],
                'pay_link' => $data['data']['linkpay'],
                'accept_price' => $data['data']['accept_price']
            ];
        } else if ($method == 'checkTransaction' && $data) {
            return $data['data'];
        } else if ($method == 'checkPenalty' && $data) {
            return $data['data'];
        } else if ($method == 'cancelTicket' && $data) {
            return $data['data'];
        } else if ($method == 'book' && $data) {
            $arr = [];
            if (isset($data['status']) && $data['status']) {
                foreach ($subData['Data']['items'] as $keyItem => $item) {
                    if ($data['status']) {
                        foreach ($item['passengers'] as $key => $passenger) {
                            if (isset($data['book'][$key]) && $data['book'][$key]['status']) {
                                $arr[] = [
                                    'Status' => true,
                                    'DepartureSegment' => [
                                        'PNR' => [
                                            'Service' => $data['book'][$key]['pnr']['local'],
                                            'Original' => $data['book'][$key]['pnr']['original']
                                        ],
                                        'Origin' => [
                                            'Iata' => $subData['SubData'][0][$subData['SubData'][0]['action']]['Origin']['Iata']['iata'], //$subData->flight->Origin->Iata,
                                            'Terminal' => $subData['SubData'][0][$subData['SubData'][0]['action']]['Origin']['Terminal'] //$subData->flight->Origin->Terminal
                                        ],
                                        'Destination' => [
                                            'Iata' => $subData['SubData'][0][$subData['SubData'][0]['action']]['Destination']['Iata']['iata'], //$subData->flight->Destination->Iata,
                                            'Terminal' => $subData['SubData'][0][$subData['SubData'][0]['action']]['Destination']['Terminal'] //$subData->flight->Destination->Terminal
                                        ],
                                        'FlightDateTime' => $subData['SubData'][0][$subData['SubData'][0]['action']]['DepartureDateTime'], //$subData->flight->DepartureDateTime,
                                        'LocalTicketNumber' => $data['book'][$key]['pnr']['id'],
                                        'OriginalTicketNumber' => $data['book'][$key]['pnr']['id'],
                                        'FlightNumber' => $subData['SubData'][0][$subData['SubData'][0]['action']]['FlightNumber'],
                                        'Description' => trim($subData['SubData'][0][$subData['SubData'][0]['action']]['Classes']['CancelationPolicy'] . ' ' . $subData['SubData'][0][$subData['SubData'][0]['action']]['Classes']['BookingPolicy']) //trim($subData->flight->Classes[$subData->class]->CancelationPolicy . ' ' . $subData->flight->Classes[$subData->class]->BookingPolicy),
                                    ],
                                    'ReturningSegment' => false,
                                ];
                            } else {
                                if (!isset($data['book'][$key])) {
                                    $tempKey = $key - 1;
                                } else {
                                    $tempKey = $key;
                                }
                                $error = ReservationController::staticGetErrorDetails(['code' => $data['book'][$tempKey]['code']]);
                                $arr[] = [
                                    'Status' => false,
                                    'Code' => $data['book'][$tempKey]['code'],
                                    'Message' => $error['title'],
                                    'Solution' => $error['solution']
                                ];
                            }
                        }
                    } else {
                        $error = ReservationController::staticGetErrorDetails(['code' => $data['book'][$keyItem]['code']]);
                        $arr[] = [
                            'Status' => false,
                            'Code' => $data['book'][$keyItem]['code'],
                            'Message' => $error['title'],
                            'Solution' => $error['solution']
                        ];
                    }
                }
            } else {
                $arr[] = [
                    'Status' => false,
                    'Time' => time(),
                    'Code' => '1501',
                    'Message' => $data
                ];
            }
            return ['Data' => $arr, 'Result' => $data];
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

    static function priceHandle($price, $values)
    {
        $return = 0;
        if (gettype($values) == 'string') {
            $values = [$values];
        }
        if ($price > 0 && $values) {
            foreach ($values as $value) {
                if (is_array($value) && $value['value'] > 0) {
                    if ($value['value_type'] == 'percentage') {
                        $return += ($price * $value['value']) / 100;
                    } else if ($value['value_type'] == 'currency') {
                        $return += $value['value'];
                    }
                }
            }
        }
        return $return;
    }

    static function getDetails($action, $data, $details = false, $branch = false)
    {
        if ($branch) {
            $tempBranch = DB::table('offices')->select('base_online')->where('id', $branch)->first();
            if (is_null($tempBranch->base_online)) {
                $data = 1;
            }
        }
        if (!$details) {
            return $data;
        } else {
            if ($action == 'airline') {
                $iataAirline = DB::table('airlines')->select('id')->where('iata', $data)->first();
                if ($iataAirline) {
                    $airline = StaticController::dataRedis('airline', $iataAirline->id);
                    return $airline;
                } else return ["iata" => $data];
            } else if ($action == 'aircraft') {
                $aircraft = json_decode(Redis::get('aircraft:' . $data), true);
                if (!$aircraft) {
                    $aircraft = DB::table('aircraft')->where('id', $data)->first();
                    if (!is_null($aircraft)) {
                        $aircraft = [
                            "id" => $aircraft->id,
                            "iata" => $aircraft->iata,
                            "icao" => $aircraft->icao,
                            "logo" => $aircraft->image,
                            "model" => $aircraft->model,
                        ];
                        Redis::set('aircraft:' . $data, json_encode($aircraft));
                    } else $aircraft = ["iata" => $data];
                }
                return $aircraft;
            } else if ($action == 'airport') {
                $airport = json_decode(Redis::get('airports:' . $data), true);
                if (!$airport) {
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
                        "id" => $airport->id,
                        "iata" => $airport->iata,
                        "title" => $airport->title,
                        "title_fa" => $airport->title_fa,
                        "country" => [
                            "id" => $airport->country_id,
                            "title_fa" => $airport->country_title_fa,
                            "title_en" => $airport->country_title_en,
                        ],
                        "state" => [
                            "id" => $airport->state_id,
                            "title_fa" => $airport->state_title_fa,
                            "title_en" => $airport->state_title_en,
                        ],
                        "city" => [
                            "id" => $airport->city_id,
                            "title_fa" => $airport->city_title_fa,
                            "title_en" => $airport->city_title_en,
                        ],
                        "priority" => $airport->priority,
                        "status" => $airport->status,
                    ];
                    Redis::set('airports:' . $data, json_encode($airport));
                }
                return $airport;
            } else if ($action == 'supplier') {
                $supplierApi = DB::table('mapping_colleagues')
                    ->select('colleague')
                    ->where('airplus', $data)
                    ->where('status', 1)
                    ->first();

                if (is_null($supplierApi) || self::$isHub) $supplierApi = (object)["colleague" => 1];
                $supplier = json_decode(Redis::get('colleagues:' . $supplierApi->colleague), true);
                if (!$supplier) {
                    $supplier = DB::table('colleagues')->select('id', 'office as title_fa', 'office as title_en', 'first_name', 'last_name', 'credit_amount', 'status')->where('id', $supplierApi->colleague)->first();
                    if (is_null($supplier)) {
                        $supplier = [
                            "id" => $data,
                            "title_fa" => $data,
                            "title_en" => $data,
                            "first_name" => $data,
                            "last_name" => $data,
                            "credit_amount" => $data,
                            "status" => $data,
                        ];
                    } else
                        Redis::set('colleagues:' . $supplierApi->colleague, json_encode($supplier));
                }
                return $supplier;
            } else if ($action == 'system_supplier') {
                $supplierApi = DB::table('mapping_colleagues')
                    ->select('colleague')
                    ->where('airplus', $data)
                    ->where('status', 1)
                    ->first();

                if (is_null($supplierApi)) $supplierApi = (object)["colleague" => 0];
                $supplier = json_decode(Redis::get('colleagues:' . $supplierApi->colleague), true);
                if (!$supplier) {
                    $supplier = DB::table('colleagues')->select('id', 'office as title_fa', 'office as title_en', 'first_name', 'last_name', 'credit_amount', 'status')->where('id', $supplierApi->colleague)->first();
                    if (is_null($supplier)) {
                        $supplier = [
                            "id" => $data,
                            "title_fa" => $data,
                            "title_en" => $data,
                            "first_name" => $data,
                            "last_name" => $data,
                            "credit_amount" => $data,
                            "status" => $data,
                        ];
                    } else {
                        Redis::set('colleagues:' . $supplierApi->colleague, json_encode($supplier));
                    }
                }
                return $supplier;
            }
        }
    }
}
