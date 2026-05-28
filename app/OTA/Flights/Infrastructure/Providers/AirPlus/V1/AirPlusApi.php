<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\AirPlus\V1;

use App\Http\Controllers\Api\Panel\V2\StaticController;
use App\Http\Controllers\Api\Reservation\V1\ReservationController;
use App\Lib\Helpers\Functions;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class AirPlusApi
{
    public static $isHub = false;

    public static $isSalesChannels = false;

    public static function sendRequest($request, array $data = [], $type = 'flight', $branch = false, $operator = false)
    {
        self::$isSalesChannels = Functions::checkSalesChannels($branch);
        if (self::$isSalesChannels) {
            $branch = self::$isSalesChannels;
        }

        $interface = DB::table('application_interface')
            ->where('branch', $branch)
            ->where('status', 1)
            ->where('type', 'api')
            ->where('service', 'airplus')
            ->first();

        if (! $interface) {
            $tempBranch = DB::table('offices')->select('type', 'base_online')->where('id', $branch)->first();
            if ($tempBranch && ! is_null($tempBranch->base_online) && $tempBranch->base_online == 1) {
                $interface = DB::table('application_interface')
                    ->where('branch', $branch)
                    ->where('status', 1)
                    ->where('type', 'api')
                    ->where('service', 'airplus')
                    ->first();

                if (! $interface) {
                    return [
                        'Data' => [
                            'Status' => false,
                            'Code' => '2007',
                            'Message' => ['Information' => 'Api not found!'],
                        ],
                    ];
                }
                self::$isHub = true;
            } else {
                // return [
                //     "Data" => [
                //         'Status' => false,
                //         'Code' => '2007',
                //         'Message' => ['Information' => 'Office or online service not found!'],
                //     ]
                // ];
                return [
                    'status' => false,
                    'time' => time(),
                    'code' => 2007,
                    'message' => 'Office or online service not found!',
                    'details' => $branch,
                ];
            }
        }

        if ($interface) {
            if ($interface->branch != $branch) {
                self::$isHub = true;
            }

            $operatorPayload = $data['subData']['operatorPayload'] ?? false;

            if ($request == 'credit') {
                $result = ReservationController::credit($data);
            } elseif ($request == 'search') {
                $result = ReservationController::staticSearch($data, $type, $branch);
            } elseif ($request == 'lock') {
                $result = ReservationController::staticLock($data, ['type' => $operatorPayload, 'id' => $operator], $branch);
            } elseif ($request == 'unlock') {
                $result = ReservationController::staticUnlock($data, $branch);
            } elseif ($request == 'itemStatus') {
                $result = ReservationController::staticItemsStatus($data, $branch);
            } elseif ($request == 'book') {
                $result = ReservationController::staticBook($data['Data'], $operator, $branch, $operatorPayload);
            } elseif ($request == 'penalty') {
                $result = ReservationController::staticRefundProcedure($data['type'], $data['reservation_code']);
            } elseif ($request == 'refund') {
                $result = ReservationController::staticRefund($data);
            }
            try {
                if ($result && ($result->getStatusCode() == 200 || $result->getStatusCode() == 201 || $result->getStatusCode() == 207)) {
                    return AirPlusApi::resultAPI2ResultSys($request, $result->getOriginalContent(), $data, $branch, $type, $interface);
                } elseif ($result && $result->getStatusCode() == 204) {
                    return true;
                } elseif ($result && $result->getStatusCode() == 422) {
                    $resultContent = $result->getOriginalContent();
                    if (isset($resultContent['error'])) {
                        $error = ReservationController::staticGetErrorDetails(['code' => $resultContent['error']['code']]);
                        $error = $error[$resultContent['error']['code']];

                        return $error['title'];
                        // return [
                        //     'status' => false,
                        //     'code' => $resultContent['error']['code'],
                        //     'message' => $error['title'],
                        //     'solution' => $error['solution']
                        // ];
                    } else {
                        return [
                            'status' => false,
                            'time' => time(),
                            'message' => $result->getStatusCode() . isset($resultContent['error']) ? ' : ' . $resultContent['error']['code'] : '',
                            'trace' => $result,
                        ];
                    }
                } else {
                    $resultContent = $result->getOriginalContent();

                    return [
                        'status' => false,
                        'time' => time(),
                        'message' => $result->getStatusCode() . isset($resultContent['error']) ? ' : ' . $resultContent['error']['code'] : '',
                        'trace' => $result,
                    ];
                }
            } catch (Exception $e) {
                return [
                    'status' => false,
                    'time' => time(),
                    "code" => $e->getCode(),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTrace(),
                ];
            }
        } else {
            return [
                'status' => false,
                'time' => time(),
                'code' => 404,
                'message' => 'Api not found!',
            ];
        }
    }

    public function getErrorMsg($error)
    {
        $Error = [
            '100' => 'پروازی با اطلاعات درخواستی پیدا نشد.',
        ];

        return $Error[$error];
    }

    public static function resultAPI2ResultSys($method, $data, $subData, $branch, $type, $serviceDetails = false)
    {
        if ($method == 'credit' && $data) {
            $arr = [
                'Status' => true,
                'Time' => time(),

                'CurrencyCode' => 'IRR',
                'Service' => 'airplus',
                'Information' => (int) $data['data'],
            ];

            return $arr;
        } elseif ($method == 'search' && $data && count($data['items']) > 0 && $type == 'accommodation') {
            foreach ($data['items'] as $i => $item) {
                $tempSupplier = AirPlusApi::getDetails('supplier', ($item['supplier'] - 10_000), $branch, $branch);
                $tempSystemSupplier = AirPlusApi::getDetails('system_supplier', ($item['supplier'] - 10_000), $branch, $branch);
                $itemIsHub = false;
                if (($item['supplier'] - 10_000) != (is_object($serviceDetails) ? $serviceDetails->branch : false)) {
                    $itemIsHub = true;
                }
                $data['items'][$i]['supplier'] = $tempSupplier;
                $data['items'][$i]['system_supplier'] = $tempSystemSupplier;
                $data['items'][$i]['displayable_service'] = self::$isHub || $itemIsHub ? 'airplusHub' : 'airplus';
            }

            if (isset($data['items']) && is_array($data['items'])) {
                return [
                    'status' => true,
                    'data' => $data['items'],
                ];
            } else {
                return [
                    'status' => false,
                    'code' => '1503',
                    'message' => $data,
                ];
            }

            return $data['items'];
        } elseif ($method == 'search' && $data && count($data['items']) > 0 && $type != 'accommodation') {
            if ($type == 'flight') {
                foreach ($data['items'] as $flight) {
                    $Classes = [];

                    $itemIsHub = false;
                    if (($flight['supplier'] - 10_000) != (is_object($serviceDetails) ? $serviceDetails->branch : false)) {
                        $itemIsHub = true;
                    }

                    foreach ($flight['items'] as $item) {
                        if ($branch) {
                            if (! self::$isSalesChannels) {
                                $tempBranch = DB::table('offices')->select('type', 'base_online')->where('id', $branch)->first();
                                if (! is_null($tempBranch) && is_null($tempBranch->base_online)) {
                                    $checkFinancial = true;
                                } else {
                                    $checkFinancial = false;
                                }
                            } else {
                                $checkFinancial = false;
                            }
                        }

                        if ($branch && $checkFinancial) {
                            if ($checkFinancial) {
                                $markup = 0;
                            } else {
                                $markup = 0;
                            }
                            $tempFinancial = $item['financial'];
                        } else {
                            $tempFinancial = $item['financial'];
                        }
                        $tempSupplier = AirPlusApi::getDetails('supplier', $flight['supplier'], $branch);
                        $tempSystemSupplier = AirPlusApi::getDetails('system_supplier', $flight['supplier'], $branch);
                        $tempFinancial = AirPlusApi::getFinancialItem($item['financial']);

                        $rules = false;
                        if (isset($item['rules']) && $item['rules']) {
                            $rules = [
                                'RefundRules' => $item['rules']['refund_rules'] ?? false,
                            ];
                        }
                        $Classes[] = [
                            'FlightStatus' => true,
                            'Reservable' => $item['reservable'],
                            'Status' => $item['reservable'] ? 'reservable' : 'full',
                            'CancelationPolicy' => false,
                            'BookingPolicy' => false,
                            'Supplier' => $tempSupplier,
                            'SystemSupplier' => $tempSystemSupplier,
                            'FlightId' => $flight['charter_id'],
                            'FareName' => $item['item_id'],
                            'CabinType' => AirPlusApi::getClassName($item['title']),
                            'AvailableSeat' => $item['statistics']['capacity'] ?: false,
                            'Services' => $item['services'],
                            'Communications' => $item['communications'],
                            'Rules' => $rules,
                            'Financial' => $tempFinancial,
                            'BaseData' => [
                                'Supplier' => [
                                    'Supplier' => $tempSupplier,
                                    'SystemSupplier' => $tempSystemSupplier,
                                ],
                                'Financial' => $tempFinancial,
                            ],
                            'Baggage' => AirPlusApi::getBaggageItem($item['baggage']),
                            'Remarks' => [
                                'Inbound' => [
                                    'AirlineSupplier' => false,
                                    'TourRequirement' => $item['features']['is_tour'],
                                    'OneWayRequirement' => false,
                                    'RoundtripRequirement' => $item['features']['is_round_trip'],
                                    'PhoneRequirement' => $item['features']['is_phone'],
                                    'FlightReturn' => false,
                                    'DateReturn' => false,
                                    'Description' => false,
                                    'Special' => true,
                                    'Warranty' => true,
                                ],
                                'Outbound' => false,
                            ],
                        ];
                    }

                    $Flights[] = [
                        'Service' => 'airplus',
                        'ServiceId' => is_object($serviceDetails) ? $serviceDetails->id : false,
                        'ServiceBranch' => is_object($serviceDetails) ? $serviceDetails->branch : false,
                        'DisplayableService' => self::$isHub || $itemIsHub ? 'airplusHub' : 'airplus',
                        'Verified' => true,
                        'FlightType' => 'Charter',
                        'FlightRoute' => ucfirst(strtolower($flight['details']['flight_route'])),
                        'FlightNumber' => $flight['details']['flight_number'],
                        'Origin' => [
                            'Iata' => AirPlusApi::getDetails('airport', $flight['details']['origin']['iata'], true),
                            'Terminal' => false,
                        ],
                        'Destination' => [
                            'Iata' => AirPlusApi::getDetails('airport', $flight['details']['destination']['iata'], true),
                            'Terminal' => false,
                        ],
                        'DepartureDateTime' => date('Y-m-d H:i:s', strtotime($flight['details']['datetime'])),
                        'ArrivalDateTime' => false,
                        'Duration' => false,
                        'Aircraft' => $flight['details']['aircraft'],
                        'Airline' => AirPlusApi::getDetails('airline', $flight['details']['airline']['iata'], true),
                        'Remarks' => [
                            'AirlineSupplier' => false,
                            'TourRequirement' => false,
                            'OneWayRequirement' => false,
                            'RoundtripRequirement' => false,
                            'PhoneRequirement' => false,
                            'FlightReturn' => false,
                            'DateReturn' => false,
                            'Description' => false,
                            'Special' => true,
                            'Warranty' => true,
                        ],
                        'ReturningFlight' => false,
                        'Steps' => false,
                        'Classes' => $Classes,
                    ];
                }
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
                        'Message' => $flight,
                    ];
                }

                return ['Data' => $arr, 'Result' => $data];
            } elseif ($type == 'train') {
                $return = [];
                foreach ($data['items'] as $result) {
                    $classes = [];
                    foreach ($result['items'] as $item) {
                        if ($branch) {
                            if (! self::$isSalesChannels) {
                                $tempBranch = DB::table('offices')->select('type', 'base_online')->where('id', $branch)->first();
                                if (! is_null($tempBranch) && is_null($tempBranch->base_online)) {
                                    $checkFinancial = true;
                                } else {
                                    $checkFinancial = false;
                                }
                            } else {
                                $checkFinancial = false;
                            }
                        }

                        if ($branch && $checkFinancial) {
                            if ($checkFinancial) {
                                $markup = 0;
                            } else {
                                $markup = 0;
                            }
                            $tempFinancial = $item['financial'];
                        } else {
                            $tempFinancial = $item['financial'];
                        }
                        $tempSupplier = AirPlusApi::getDetails('supplier', ($result['supplier'] - 10_000), true, $branch, true);
                        $tempSystemSupplier = AirPlusApi::getDetails('system_supplier', ($result['supplier'] - 10_000), true, $branch, true);
                        $tempFinancial = AirPlusApi::getFinancialItem($item['financial'], true);
                        $classes[] = [
                            'status' => true,
                            'reservable' => $item['reservable'],
                            'policy' => [
                                'cancellation' => false,
                                'booking' => false,
                            ],
                            'supplier' => $tempSupplier,
                            'system_supplier' => $tempSystemSupplier,
                            'item_id' => $result['charter_id'],
                            'class_id' => $item['item_id'],
                            'cabin_type' => $item['title'],
                            'available' => $item['statistics']['capacity'],
                            'services' => $item['services'],
                            'communications' => $item['communications'],
                            'financial' => $tempFinancial,
                            'base_data' => [
                                'supplier' => [
                                    'supplier' => $tempSupplier,
                                    'system_supplier' => $tempSystemSupplier,
                                ],
                                'financial' => $tempFinancial,
                            ],
                            'remarks' => [
                                'inbound' => [
                                    'tour_requirement' => $item['tour_requirement'],
                                    'roundtrip_requirement' => false,
                                    'phone_requirement' => false,
                                    'return' => false,
                                    'description' => false,
                                    'special' => true,
                                    'warranty' => true,
                                ],
                                'outbound' => false,
                            ],
                        ];
                    }

                    $duration = false;
                    $arrivalDate = false;
                    if (isset($result['details']['duration']) && $result['details']['duration']) {
                        $hours = floor($result['details']['duration'] / 60);
                        $remainingMinutes = $result['details']['duration'] % 60;

                        $duration = sprintf('%02d:%02d', $hours, $remainingMinutes);

                        $originalDate = Carbon::parse($result['details']['datetime']);
                        $arrivalDate = $originalDate->copy()->addMinutes($result['details']['duration']);
                        $arrivalDate = $arrivalDate->setTimezone('Asia/Tehran')->format('Y-m-d H:i:s');
                    }
                    $return[] = [
                        'service' => [
                            'name' => 'airplus',
                            'id' => is_object($serviceDetails) ? $serviceDetails->id : false,
                            'branch' => is_object($serviceDetails) ? $serviceDetails->branch : false,
                            'displayable' => self::$isHub ? 'airplusHub' : 'airplus',
                            'verified' => true,
                            'type' => 'charter'
                        ],
                        'route' => isset($result['details']['route']) ? strtolower($result['details']['route']) : 'internal',
                        'number' => !is_null($result['details']['number']) ? $result['details']['number'] : false,
                        'origin' => AirPlusApi::getDetails('city', $result['details']['origin']['id'], $subData['origin'] ?? true),
                        'destination' => AirPlusApi::getDetails('city', $result['details']['destination']['id'], $subData['destination'] ?? true),
                        'departure_datetime' => date('Y-m-d H:i:s', strtotime($result['details']['datetime'])),
                        'returning_datetime' => false,
                        'duration' => $duration,
                        'arrival_date' => $arrivalDate,
                        'vehicle' => AirPlusApi::getDetails('vehicle', $result['details']['vehicle']['id'], true),
                        'company' => AirPlusApi::getDetails('trainCompany', $result['details']['company']['id'], true),
                        'requirements' => false,
                        'steps' => false,
                        'classes' => $classes
                    ];
                }

                if (isset($return)) {
                    return [
                        'status' => true,
                        'data' => $return
                    ];
                } else {
                    return [
                        'status' => false,
                        'code' => '1503',
                        'message' => $result
                    ];
                }
            }
        } elseif ($method == 'lock' && $data) {
            return $data['items'];
        } elseif ($method == 'itemStatus' && $data) {
            return $data['items'];
        } elseif ($method == 'penalty' && $data) {
            return $data['payload'];
        } elseif ($method == 'refund' && $data) {
            return $data['payload'];
        } elseif ($method == 'book' && $data) {
            if ($type == 'flight') {
                $arr = [];
                foreach ($subData['Data']['items'] as $keyItem => $item) {
                    foreach ($item['passengers'] as $key => $passenger) {
                        if (isset($data['items'][$key]) && $data['items'][$key]['status']) {
                            $arr[] = [
                                'Status' => true,
                                'DepartureSegment' => [
                                    'PNR' => [
                                        'Service' => $data['items'][$key]['pnr']['local'],
                                        'Original' => $data['items'][$key]['pnr']['original'],
                                    ],
                                    'Origin' => [
                                        'Iata' => $subData['subData']['origin']['iata'],
                                        'Terminal' => $subData['subData']['origin']['terminal'],
                                    ],
                                    'Destination' => [
                                        'Iata' => $subData['subData']['destination']['iata'],
                                        'Terminal' => $subData['subData']['destination']['terminal'],
                                    ],
                                    'FlightDateTime' => $subData['subData']['departureDateTime'],
                                    'LocalTicketNumber' => $data['items'][$key]['pnr']['id'],
                                    'OriginalTicketNumber' => $data['items'][$key]['pnr']['id'],
                                    'FlightNumber' => $subData['subData']['flightNumber'],
                                    'Description' => $subData['subData']['description'],
                                ],
                                'ReturningSegment' => false,
                            ];
                        } else {
                            if (! isset($data['items'][$key])) {
                                $tempKey = $key - 1;
                                if (! isset($data['items'][$tempKey])) {
                                    $tempKey = 0;
                                }
                            } else {
                                $tempKey = $key;
                            }
                            $error = ReservationController::staticGetErrorDetails(['code' => $data['items'][$tempKey]['code']]);
                            $error = $error[$data['items'][$tempKey]['code']];
                            $arr[] = [
                                'Status' => false,
                                'Code' => $data['items'][$tempKey]['code'],
                                'Message' => $error['title'],
                                'Solution' => $error['solution'],
                            ];
                        }
                    }
                }

                return ['Data' => $arr, 'Result' => $data];
            } elseif ($type == 'train') {
                $arr = [];
                foreach ($subData['Data']['items'] as $keyItem => $item) {
                    foreach ($item['passengers'] as $key => $passenger) {
                        if (isset($data['items'][$key]) && $data['items'][$key]['status']) {
                            $arr[] = [
                                'Status' => true,
                                'DepartureSegment' => [
                                    'PNR' => [
                                        'Service' => $data['items'][$key]['pnr']['local'],
                                        'Original' => $data['items'][$key]['pnr']['original'],
                                    ],
                                    'Origin' => [
                                        'Iata' => $subData['subData']['origin']['iata'],
                                        'Terminal' => false,
                                    ],
                                    'Destination' => [
                                        'Iata' => $subData['subData']['destination']['iata'],
                                        'Terminal' => false,
                                    ],
                                    'DepartureDateTime' => $subData['subData']['departureDateTime'],
                                    'LocalTicketNumber' => $data['items'][$key]['pnr']['id'],
                                    'OriginalTicketNumber' => $data['items'][$key]['pnr']['id'],
                                    'Number' => $subData['subData']['number'],
                                    'Description' => $subData['subData']['description'],
                                ],
                                'ReturningSegment' => false,
                            ];
                        } else {
                            if (! isset($data['items'][$key])) {
                                $tempKey = $key - 1;
                                if (! isset($data['items'][$tempKey])) {
                                    $tempKey = 0;
                                }
                            } else {
                                $tempKey = $key;
                            }
                            $error = ReservationController::staticGetErrorDetails(['code' => $data['items'][$tempKey]['code']]);
                            $error = $error[$data['items'][$tempKey]['code']];
                            $arr[] = [
                                'Status' => false,
                                'Code' => $data['items'][$tempKey]['code'],
                                'Message' => $error['title'],
                                'Solution' => $error['solution'],
                            ];
                        }
                    }
                }

                return ['Data' => $arr, 'Result' => $data];
            } elseif ($type == 'accommodation') {
                if (isset($data['items'][0]) && $data['items'][0]['status']) {
                    return $data['items'];
                } else {
                    $error = ReservationController::staticGetErrorDetails(['code' => $data['items'][0]['code']]);
                    $error = $error[$data['items'][0]['code']];
                    return $error['title'];
                }
            }
        }
    }

    public static function getDetails($action, $data, $details = false, $branch = false, $new = false)
    {
        if ($branch) {
            $tempBranch = DB::table('offices')->select('base_online')->where('id', $branch)->first();
            if (is_null($tempBranch->base_online)) {
                $data = 1;
            }
        }

        if (! $details) {
            return $data;
        }

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
            $supplierApi = DB::table('mapping_colleagues')
                ->select('colleague')
                ->where('airplus', $data)
                ->where('status', 1)
                ->first();

            if (is_null($supplierApi) || self::$isHub) {
                $supplierApi = (object) ['colleague' => 1];
            }

            if (! self::$isHub) {
                $colleagueOfficeCharter = DB::table('office_config')->select('value')->where('office', $branch)->where('key', 'CHARTER_OFFICE_COLLEAGUE')->first();
                $supplierApi = (object) ['colleague' => (! is_null($colleagueOfficeCharter) ? $colleagueOfficeCharter->value : 1)];
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

            if ($new) {
                $supplier = [
                    'id' => $supplier['id'],
                    'title' => [
                        'fa' => $supplier['title_fa'],
                        'en' => $supplier['title_en'],
                    ],
                    'status' => $supplier['status'],
                    'details' => [
                        'first_name' => $supplier['first_name'],
                        'last_name' => $supplier['last_name'],
                        'credit_amount' => $supplier['credit_amount'],
                    ],
                ];
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

            if ($new) {
                $supplier = [
                    'id' => $supplier['id'],
                    'title' => [
                        'fa' => $supplier['title_fa'],
                        'en' => $supplier['title_en'],
                    ],
                    'status' => $supplier['status'],
                    'details' => [
                        'first_name' => $supplier['first_name'],
                        'last_name' => $supplier['last_name'],
                        'credit_amount' => $supplier['credit_amount'],
                    ],
                ];
            }

            return $supplier;
        } elseif ($action == 'city') {
            $result = DB::table('cities')->select('id', 'en_name', 'fa_name')->where('id', $data)->first();

            return [
                'id' => $result->id,
                'iata' => $details ? $details : false,
                'title' => $result->en_name,
                'title_fa' => $result->fa_name,
            ];
        } elseif ($action == 'vehicle') {
            return [
                'id' => 1,
                'title' => [
                    'fa' => 'قطار',
                    'en' => 'train',
                ],
            ];
        } elseif ($action == 'company') {
            $supplierApi = DB::table('mapping_colleagues')
                ->select('colleague')
                ->where('airplus', $data)
                ->where('status', 1)
                ->first();

            if (is_null($supplierApi) || self::$isHub) {
                $supplierApi = (object) ['colleague' => 1];
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

            if ($new) {
                $supplier = [
                    'id' => $supplier['id'],
                    'title' => [
                        'fa' => $supplier['title_fa'],
                        'en' => $supplier['title_en'],
                    ],
                    'status' => $supplier['status'],
                    'details' => [
                        'first_name' => $supplier['first_name'],
                        'last_name' => $supplier['last_name'],
                        'credit_amount' => $supplier['credit_amount'],
                    ],
                ];
            }

            return $supplier;
        } elseif ($action == 'trainCompany') {
            $trainCompany = DB::table('train_companies')->select('title', 'status', 'logo')->where('id', $data)->first();
            $supplier = [
                'id' => $data,
                'logo' => [
                    'small' => ! is_null($trainCompany->logo) ? 'https://storage.service01.ir/media/logo/train-company/small/' . $trainCompany->logo : false,
                    'large' => ! is_null($trainCompany->logo) ? 'https://storage.service01.ir/media/logo/train-company/large/' . $trainCompany->logo : false,
                ],
                'title' => [
                    'fa' => $trainCompany->title,
                    'en' => false,
                ],
                'status' => $trainCompany->status,
                'details' => false,
            ];

            return $supplier;
        }
    }

    public static function getClassName($class)
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
            'iata' => $class,
            'title' => [
                'fa' => $tempClassTitleFa,
                'en' => $tempClass,
            ],
        ];
    }

    public static function getFinancialItem($financial, $toLower = false)
    {
        $infantKey = 'infant';
        if (! isset($financial['infant'])) {
            $infantKey = isset($financial['child']) ? 'child' : 'adult';
        }
        $return = [
            'PriceAdditions' => [
                'Citizens' => false,
            ],
            'CommissionPaid' => [
                'Percentage' => false,
                'Transaction' => false,
                'MembershipRight' => false,
            ],
            'Adult' => [
                'BaseFare' => $financial['adult']['base_fare'],
                'Tax' => AirPlusApi::priceHandle($financial['adult']['base_fare'], $financial['adult']['taxes']),
                'Markup' => AirPlusApi::priceHandle($financial['adult']['base_fare'], $financial['adult']['markups']),
                'TotalFare' => $financial['adult']['total_fare'],
                'Payable' => $financial['adult']['payable'],
                'Commission' => [
                    'Percentage' => $financial['adult']['commissions'] ? ($financial['adult']['commissions'][0]['value_type'] == 'percentage' ? true : false) : false,
                    'Final' => $financial['adult']['commissions'] ? AirPlusApi::priceHandle($financial['adult']['base_fare'], $financial['adult']['commissions']) : false,
                    'Price' => AirPlusApi::priceHandle($financial['adult']['base_fare'], $financial['adult']['commissions']),
                ],
            ],
            'Child' => [
                'BaseFare' => $financial['child']['base_fare'],
                'Tax' => AirPlusApi::priceHandle($financial['child']['base_fare'], $financial['child']['taxes']),
                'Markup' => AirPlusApi::priceHandle($financial['child']['base_fare'], $financial['child']['markups']),
                'TotalFare' => $financial['child']['total_fare'],
                'Payable' => $financial['child']['payable'],
                'Commission' => [
                    'Percentage' => $financial['child']['commissions'] ? ($financial['child']['commissions'][0]['value_type'] == 'percentage' ? true : false) : false,
                    'Final' => $financial['child']['commissions'] ? AirPlusApi::priceHandle($financial['child']['base_fare'], $financial['child']['commissions']) : false,
                    'Price' => AirPlusApi::priceHandle($financial['child']['base_fare'], $financial['child']['commissions']),
                ],
            ],
            'Infant' => [
                'BaseFare' => $financial[$infantKey]['base_fare'],
                'Tax' => AirPlusApi::priceHandle($financial[$infantKey]['base_fare'], $financial[$infantKey]['taxes']),
                'Markup' => AirPlusApi::priceHandle($financial[$infantKey]['base_fare'], $financial[$infantKey]['markups']),
                'TotalFare' => $financial[$infantKey]['total_fare'],
                'Payable' => $financial[$infantKey]['payable'],
                'Commission' => [
                    'Percentage' => $financial[$infantKey]['commissions'] ? ($financial[$infantKey]['commissions'][0]['value_type'] == 'percentage' ? true : false) : false,
                    'Final' => $financial[$infantKey]['commissions'] ? AirPlusApi::priceHandle($financial[$infantKey]['base_fare'], $financial[$infantKey]['commissions']) : false,
                    'Price' => AirPlusApi::priceHandle($financial[$infantKey]['base_fare'], $financial[$infantKey]['commissions']),
                ],
            ],
        ];

        if ($toLower) {
            // Convert all keys to snake_case recursively
            $return = StaticController::arrayChangeKeyCaseRecursive($return);
        }

        return $return;
    }

    public static function getBaggageItem($baggage)
    {
        return [
            'Adult' => [
                'Trunk' => [
                    'Number' => $baggage['trunk']['adult']['number'],
                    'TotalWeight' => $baggage['trunk']['adult']['weight'],
                ],
                'Hand' => [
                    'Number' => $baggage['hand']['adult']['number'],
                    'TotalWeight' => $baggage['hand']['adult']['weight'],
                ],
            ],
            'Child' => [
                'Trunk' => [
                    'Number' => $baggage['trunk']['child']['number'],
                    'TotalWeight' => $baggage['trunk']['child']['weight'],
                ],
                'Hand' => [
                    'Number' => $baggage['hand']['child']['number'],
                    'TotalWeight' => $baggage['hand']['child']['weight'],
                ],
            ],
            'Infant' => [
                'Trunk' => [
                    'Number' => $baggage['trunk']['infant']['number'],
                    'TotalWeight' => $baggage['trunk']['infant']['weight'],
                ],
                'Hand' => [
                    'Number' => $baggage['hand']['infant']['number'],
                    'TotalWeight' => $baggage['hand']['infant']['weight'],
                ],
            ],
        ];
    }

    public static function priceHandle($price, $values)
    {
        if ($values) {
            $return = 0;
            if ($price > 0 && $values) {
                foreach ($values as $value) {
                    if ($value && isset($value['value'])) {
                        if ($value['value'] > 0) {
                            if ($value['value_type'] == 'percent') {
                                $return += ($price * $value['value']) / 100;
                            } elseif ($value['value_type'] == 'currency') {
                                $return += $value['value'];
                            }
                        }
                    } else {
                        foreach ($value as $item) {
                            if (isset($item['value']) && $item['value']) {
                                if ($item['value_type'] == 'percent') {
                                    $return += ($price * $item['value']) / 100;
                                } elseif ($item['value_type'] == 'currency') {
                                    $return += $item['value'];
                                }
                            }
                        }
                    }
                }
            }
        } else {
            $return = false;
        }

        return $return;
    }
}
