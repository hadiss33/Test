<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Nira\V1;

use App\Http\Controllers\Api\Panel\V2\StaticController;
use App\Lib\Auxiliary\Visa;
use App\Lib\Helpers\Functions;
use App\Models\Airport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class NiraApi
{
    public static $isHub = false;

    static function sendRequestFlight($Iata, $request, array $data = [], array $subData = [], $method = 'GET')
    {
        switch ($request) {
            case 'CurrentBalance':
            case 'flight':
            case 'fare':
            case 'reserve_information':
            case 'ticket_information':
            case 'penalty':
            case 'penalty_now':
            case 'command':
                $BaseApi = 1;
                break;
            case 'reserve':
            case 'cancel_pnr':
            case 'ticket_issuance':
            case 'cancel_seat':
            case 'ticket_refund':
                $BaseApi = 2;
                break;
            default:
                $BaseApi = 1;
                break;
        }
        $requestData = request();
        if (!empty($requestData->get('branch'))) {
            $branch = $requestData->get('branch');
        } else {
            $branch = false;
        }

        if (!$branch) {
            $branch = 1;
        }
        $airline = DB::table('application_interface')->where('service', 'nira')
            ->whereJsonContains('data->iata', $Iata)
            ->where(function ($query) use ($request) {
                if ($request != 'ticket_information') {
                    $query->where('status', 1);
                }
            })
            ->where('branch', $branch)
            ->first();
        if (!$airline) {
            $tempBranch = DB::table('offices')->select('type', 'base_online')->where('id', str_replace('b2c-', '', $branch))->first();
            if (isset($tempBranch->base_online) && !is_null($tempBranch->base_online) && $tempBranch->base_online == 1) {
                $airline = DB::table('application_interface')->where('service', 'nira')
                    ->where('branch', 1)
                    ->whereJsonContains('data->iata', $Iata)
                    ->where(function ($query) use ($request) {
                        if ($request != 'ticket_information') {
                            $query->where('status', 1);
                        }
                    })
                    ->first();
            }
            if (!$airline) {
                return [
                    'Status' => false,
                    'Time' => time(),
                    'Code' => '1503',
                    'Message' => 'airline not found.'
                ];
            }
        }
        if ($airline->branch == 1) {
            self::$isHub = true;
        }
        $airlineData = json_decode($airline->data, true);

        $url = $airline->url;
        if ($BaseApi == 2) {
            $url = $airlineData['url'];
        }

        $Method = [
            // CurrentBalance
            'CurrentBalance' => $url . '/NRSCommand.jsp', // دریافت لیست پرواز
            'flight' => $url . '/AvailabilityFareJS.jsp',
            'fare' => $url . '/FareJS.jsp',
            'reserve' => $url . '/ReservJS',
            'reserve_information' => $url . '/NRSRT.jsp',
            'cancel_pnr' => $url . '/CancelPNRJS',
            'ticket_issuance' => $url . '/ETIssueJS',
            'ticket_information' => $url . '/NRSETR.jsp',
            'cancel_seat' => $url . '/CancelSeatJS',
            'penalty' => $url . '/NRSPenalty.jsp',
            'penalty_now' => $url . '/NRSPenaltyNow.jsp',
            'ticket_refund' => $url . '/ETRefundJS',
            'command' => $url . '/NRSCommand.jsp',
        ];

        $param = ['OfficeUser' => $airline->username, 'OfficePass' => $airline->password];

        foreach ($data as $key => $value) $param[$key] = $value;

        // return self::resultAPI2ResultSys('flight', $request, Http::get($Method[$request], $param)->throw()->json());
        // return $param;
        try {
            if ($request == 'ticket_issuance') {
                $result = file_get_contents($Method[$request] . '?' . http_build_query($param));
                $result = str_replace(["\n", "\r"], ['', ' '], $result);
            } elseif ($method == 'GET' and $request != 'fare') {
                $result = Http::get($Method[$request], $param)->throw()->json();
            } elseif ($method == 'GET' and $request == 'fare') {
                $result = Http::get($Method[$request], $param)->throw();
                $stringResponse = mb_convert_encoding($result, 'UTF-8', 'auto');
                $result = json_decode($stringResponse, true);
            } else {
                $result = Http::post($Method[$request], $param)->throw()->json();
            }

            if ($request == 'CurrentBalance' or $request == 'command' or $request == 'ticket_information' or $request == 'reserve_information') {
                if (is_null($result)) {
                    $result = file_get_contents($Method[$request] . '?' . http_build_query($param));
                    $result = str_replace(["\n", "\r"], ['', ' '], $result);
                }
            }

            return self::resultAPI2ResultSys($request, $request, $result, false, $subData, $airline, $branch);
        } catch (Throwable $e) {
            return [
                "status" => false,
                "time" => time(),
                "message" => $e->getMessage(),
                "trace" => $e->getTrace(),
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

    static function resultAPI2ResultSys($goal, $method, $data, $serviceId = false, $subData, $airline, $branch)
    {
        if ($goal == 'flight' and $data) {
            foreach ($data['AvailableFlights'] as $flight) {
                $Classes = [];
                foreach ($flight['ClassesStatus'] as $item) {
                    if (is_numeric($item['Price']) && $item['Price'] !== '-') {
                        $lastCharCap = substr($item['Cap'], -1);
                        if ($lastCharCap == 'A') {
                            $classStatus = 'reservable';
                            $availableSeat = 9;
                            $reservable = true;
                        } elseif ($lastCharCap == 'X') {
                            $classStatus = 'canceled';
                            $availableSeat = false;
                            $reservable = false;
                        } elseif ($lastCharCap == 'C') {
                            $classStatus = 'full';
                            $availableSeat = false;
                            $reservable = false;
                        } else {
                            $classStatus = 'reservable';
                            $availableSeat = $lastCharCap;
                            $reservable = true;
                        }
                        $fareDepartureDateTime = explode(' ', $flight['DepartureDateTime']);
                        // $fareInformation = json_decode(Redis::connection('demo')->get('nira:' . $flight['Airline'] . ':' . $flight['FlightNo'] . ':' . $fareDepartureDateTime[0] . ':' . $item['FlightClass']), true);
                        // if ($fareInformation) {
                        //     $tempFinancial = $fareInformation['fare'];
                        //     $baggageInf = $fareInformation['baggage'];
                        // } else {
                        $fareResponse = self::sendRequestFlight($flight['Airline'], 'fare', [
                            'AirLine' => '*',
                            'Route' => $flight['Origin'] . '-' . $flight['Destination'],
                            'RBD' => $item['FlightClass'],
                            'FlightNo' => $flight['FlightNo'],
                            'DepartureDate' => $fareDepartureDateTime[0],
                        ]);
                        $tempFinancial = $fareResponse['fare'];
                        $baggageInf = $fareResponse['baggage'];

                        $parsedTime = Carbon::parse($flight['DepartureDateTime']);
                        $now = Carbon::now();
                        $secondsDifference = $now->diffInSeconds($parsedTime, false);

                        Redis::connection('demo')->set('nira:' . $flight['Airline'] . ':' . $flight['FlightNo'] . ':' . $fareDepartureDateTime[0] . ':' . $item['FlightClass'], json_encode($fareResponse), 'EX', (int) $secondsDifference);
                        // }
                        $Classes[] = [
                            'FlightStatus' => true,
                            'Reservable' => $reservable,
                            'Status' => $classStatus,
                            'CancelationPolicy' => false,
                            'BookingPolicy' => false,
                            'Supplier' => self::getDetails('supplier', 1, true, $branch, (array) $airline),
                            'SystemSupplier' =>  self::getDetails('system_supplier', 34, true, $branch, (array) $airline),
                            'FlightId' => false,
                            'FareName' => false,
                            'CabinType' => self::getClassName($item['FlightClass']),
                            'AvailableSeat' => $availableSeat,
                            'Rules' => false,
                            'Financial' => $tempFinancial,
                            'BaseData' => [
                                "Supplier" => [
                                    'Supplier' => self::getDetails('supplier', 1, true, $branch, (array) $airline),
                                    'SystemSupplier' =>  self::getDetails('system_supplier', 34, true, $branch, (array) $airline)
                                ],
                                'Financial' => $tempFinancial
                            ],
                            'Baggage' => $baggageInf,
                            'Remarks' => [
                                'Inbound' => [
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
                                "Outbound" => false
                            ]
                        ];
                    }
                }

                if (empty($Classes)) {
                    $Classes[] = [
                        'FlightStatus' => false,
                        'Reservable' => false,
                        'Status' => 'full',
                        'CancelationPolicy' => false,
                        'BookingPolicy' => false,
                        'Supplier' => self::getDetails('supplier', 1, true, $branch, (array) $airline),
                        'SystemSupplier' =>  self::getDetails('system_supplier', 34, true, $branch, (array) $airline),
                        'FlightId' => false,
                        'FareName' => false,
                        'CabinType' => self::getClassName('Y'),
                        'AvailableSeat' => false,
                        'Rules' => false,
                        'Financial' => [
                            'PriceAdditions' => [
                                'Citizens' => false,
                            ],
                            'CommissionPaid' => [
                                'Percentage' => false,
                                'Transaction' => false,
                                'MembershipRight' => false,
                            ],
                            'Adult' => [
                                'BaseFare' => false,
                                'Tax' => false,
                                'Markup' => false,
                                'TotalFare' => false,
                                'Payable' => 0,
                                'Commission' => [
                                    'Percentage' => false,
                                    'Final' => false,
                                    'Price' => false
                                ]
                            ],
                            'Child' => [
                                'BaseFare' => false,
                                'Tax' => false,
                                'Markup' => false,
                                'TotalFare' => false,
                                'Payable' => false,
                                'Commission' => [
                                    'Percentage' => false,
                                    'Final' => false,
                                    'Price' => false
                                ]
                            ],
                            'Infant' => [
                                'BaseFare' => false,
                                'Tax' => false,
                                'Markup' => false,
                                'TotalFare' => false,
                                'Payable' => false,
                                'Commission' => [
                                    'Percentage' => false,
                                    'Final' => false,
                                    'Price' => false
                                ]
                            ]
                        ],
                        'BaseData' => [
                            "Supplier" => [
                                'Supplier' => self::getDetails('supplier', 1, true, $branch, (array) $airline),
                                'SystemSupplier' =>  self::getDetails('system_supplier', 34, true, $branch, (array) $airline)
                            ],
                            'Financial' => [
                                'PriceAdditions' => [
                                    'Citizens' => false,
                                ],
                                'CommissionPaid' => [
                                    'Percentage' => false,
                                    'Transaction' => false,
                                    'MembershipRight' => false,
                                ],
                                'Adult' => [
                                    'BaseFare' => false,
                                    'Tax' => false,
                                    'Markup' => false,
                                    'TotalFare' => false,
                                    'Payable' => 0,
                                    'Commission' => [
                                        'Percentage' => false,
                                        'Final' => false,
                                        'Price' => false
                                    ]
                                ],
                                'Child' => [
                                    'BaseFare' => false,
                                    'Tax' => false,
                                    'Markup' => false,
                                    'TotalFare' => false,
                                    'Payable' => false,
                                    'Commission' => [
                                        'Percentage' => false,
                                        'Final' => false,
                                        'Price' => false
                                    ]
                                ],
                                'Infant' => [
                                    'BaseFare' => false,
                                    'Tax' => false,
                                    'Markup' => false,
                                    'TotalFare' => false,
                                    'Payable' => false,
                                    'Commission' => [
                                        'Percentage' => false,
                                        'Final' => false,
                                        'Price' => false
                                    ]
                                ]
                            ]
                        ],
                        'Baggage' => [
                            'Adult' => [
                                'Trunk' => [
                                    'Number' => false,
                                    'TotalWeight' => false
                                ],
                                'Hand' => [
                                    'Number' => false,
                                    'TotalWeight' => false
                                ]
                            ],
                            'Child' => [
                                'Trunk' => [
                                    'Number' => false,
                                    'TotalWeight' => false
                                ],
                                'Hand' => [
                                    'Number' => false,
                                    'TotalWeight' => false
                                ]
                            ],
                            'Infant' => [
                                'Trunk' => [
                                    'Number' => false,
                                    'TotalWeight' => false
                                ],
                                'Hand' => [
                                    'Number' => false,
                                    'TotalWeight' => false
                                ]
                            ]
                        ],
                        'Remarks' => [
                            'Inbound' => [
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
                            "Outbound" => false
                        ]
                    ];
                }

                $origin = Airport::select('country')->where('iata', $flight['Origin'])->first();
                $destination = Airport::select('country')->where('iata', $flight['Destination'])->first();

                $Flights[] = [
                    'Service' => 'nira',
                    'ServiceId' => $serviceId ?: false,
                    'ServiceBranch' => $serviceId ?: false,
                    'DisplayableService' => self::$isHub ? 'airplusHub' : 'nira',
                    'Verified' => true,
                    'FlightType' => 'System',
                    'FlightRoute' => ($origin['country'] != env('COUNTRY_CODE') || $destination['country'] != env('COUNTRY_CODE')) ? 'International' : 'Internal',
                    'FlightNumber' => $flight['FlightNo'],
                    'Origin' => [
                        'Iata' => self::getDetails('airport', $flight['Origin'], true),
                        'Terminal' => false
                    ],
                    'Destination' => [
                        'Iata' => self::getDetails('airport', $flight['Destination'], true),
                        'Terminal' => false
                    ],
                    'DepartureDateTime' => $flight['DepartureDateTime'],
                    'ArrivalDateTime' => $flight['ArrivalDateTime'],
                    'Duration' => false,
                    'Aircraft' => self::getDetails('aircraft', $flight['AircraftTypeCode'], true),
                    'Airline' => self::getDetails('airline', $flight['Airline'], true),
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
                    'Classes' => $Classes
                ];
            }
            if (isset($Flights)) {
                $arr = [
                    'Status' => true,
                    'Time' => time(),
                    'CurrencyCode' => 'IRR',
                    'Information' => $Flights
                ];
            } else {
                $arr = [
                    'Status' => false,
                    'Time' => time(),
                    'Code' => '1503',
                    'Message' => false,
                    'Data' => $data['AvailableFlights']
                ];
            }
            return ["Data" => $arr, 'Result' => $data];
        } elseif ($method == 'fare' and $data) {
            $childTaxes = explode(',', $data['ChildTaxes']);
            $childTaxPrice = 0;
            foreach ($childTaxes as $i => $childTax) {
                $childTax = explode(':', $childTax);
                if ($i % 2 == 0) {
                    $childTaxPrice += (int)$childTax[count($childTax) - 1];
                }
            }

            $infantTaxes = explode(',', $data['InfantTaxes']);
            $infantTaxPrice = 0;
            foreach ($infantTaxes as $i => $infantTax) {
                $infantTax = explode(':', $infantTax);
                if ($i % 2 == 0) {
                    $infantTaxPrice += (int)$infantTax[count($infantTax) - 1];
                }
            }

            $adultTaxes = explode(',', $data['AdultTaxes']);
            $adultTaxPrice = 0;
            foreach ($adultTaxes as $i => $adultTax) {
                $adultTax = explode(':', $adultTax);
                if ($i % 2 == 0) {
                    $adultTaxPrice += (int)$adultTax[count($adultTax) - 1];
                }
            }

            $fare = [
                'PriceAdditions' => [
                    'Citizens' => false,
                ],
                'CommissionPaid' => [
                    'Percentage' => false,
                    'Transaction' => false,
                    'MembershipRight' => false,
                ],
                'Adult' => [
                    'BaseFare' => (int)$data['AdultFare'],
                    'Tax' => $adultTaxPrice,
                    'Markup' => false,
                    'TotalFare' => (int)$data['AdultTotalPrice'],
                    'Payable' => (int)$data['AdultTotalPrice'],
                    'Commission' => [
                        'Percentage' => false,
                        'Final' => false,
                        'Price' => false
                    ]
                ],
                'Child' => [
                    'BaseFare' => (int)$data['ChildFare'],
                    'Tax' => $childTaxPrice,
                    'Markup' => false,
                    'TotalFare' => (int)$data['ChildTotalPrice'],
                    'Payable' => (int)$data['ChildTotalPrice'],
                    'Commission' => [
                        'Percentage' => false,
                        'Final' => false,
                        'Price' => false
                    ]
                ],
                'Infant' => [
                    'BaseFare' => (int)$data['InfantFare'],
                    'Tax' => $infantTaxPrice,
                    'Markup' => false,
                    'TotalFare' => (int)$data['InfantTotalPrice'],
                    'Payable' => (int)$data['InfantTotalPrice'],
                    'Commission' => [
                        'Percentage' => false,
                        'Final' => false,
                        'Price' => false
                    ]
                ]
            ];

            $baggage = [
                'Adult' => [
                    'Trunk' => [
                        'Number' => $data['BaggageAllowancePieces'],
                        'TotalWeight' => $data['BaggageAllowanceWeight']
                    ],
                    'Hand' => [
                        'Number' => false,
                        'TotalWeight' => false
                    ]
                ],
                'Child' => [
                    'Trunk' => [
                        'Number' => $data['BaggageAllowancePieces'],
                        'TotalWeight' => $data['BaggageAllowanceWeight']
                    ],
                    'Hand' => [
                        'Number' => false,
                        'TotalWeight' => false
                    ]
                ],
                'Infant' => [
                    'Trunk' => [
                        'Number' => $data['BaggageAllowancePieces'],
                        'TotalWeight' => $data['BaggageAllowanceWeight']
                    ],
                    'Hand' => [
                        'Number' => false,
                        'TotalWeight' => false
                    ]
                ]
            ];
            return [
                'fare' => $fare,
                'baggage' => $baggage
            ];
        } elseif ($method == 'reserve' and $data) {
            return $data;
        } elseif ($method == 'reserve_information' and $data) {
            return $data;
        } elseif ($method == 'cancel_pnr' and $data) {
            return $data;
        } elseif ($method == 'ticket_issuance' and $data) {
            Visa::addSystemReport($data);
            $data = json_decode($data, true);
            $arr = [];
            foreach ($data['AirNRSTICKETS'] as $item) {
                if (isset($item['Tickets']) && $item['Tickets']) {
                    if (str_contains($item['Tickets'], '=')) {
                        $matchedCount = preg_match_all('/\S+\/\S+=\d+/', $item['Tickets'], $matches);
                        if ($matchedCount > 0) {
                            $tickets = $matches[0];
                            $reversedTickets = array_reverse($tickets);
                            foreach ($reversedTickets as $ticket) {
                                $arr[] = [
                                    'Status' => true,
                                    'DepartureSegment' => [
                                        'PNR' => [
                                            'Service' => $subData['subData']['lockId'],
                                            'Original' => $subData['subData']['lockId']
                                        ],
                                        'Origin' => [
                                            'Iata' => $subData['subData']['origin']['iata'],
                                            'Terminal' => $subData['subData']['origin']['terminal']
                                        ],
                                        'Destination' => [
                                            'Iata' => $subData['subData']['destination']['iata'],
                                            'Terminal' => $subData['subData']['destination']['terminal']
                                        ],
                                        'FlightDateTime' => $subData['subData']['departureDateTime'],
                                        'LocalTicketNumber' => null,
                                        'OriginalTicketNumber' => explode('=', $ticket)[1],
                                        'FlightNumber' => $subData['subData']['flightNumber'],
                                        'Description' => $subData['subData']['description']
                                    ],
                                    'ReturningSegment' => false,
                                ];
                            }
                        } else {
                            Visa::addSystemReport($data);
                            $arr = [
                                'Status' => false,
                                'Message' => $data,
                            ];
                        }
                    } elseif (str_contains($item['Tickets'], 'NOT ENOUGH CREDIT')) {
                        Visa::addSystemReport($data);
                        $arr = [
                            'Status' => false,
                            'Message' => 'در تخصیص اعتبار از سمت تامین کننده مشکلی رخ داده، لطفا چند دقیقه بعد دوباره بررسی نمایید.',
                        ];
                        $message = '🚫 ' . $item['Tickets'] . chr(10);
                        $message .= 'Branch : ' . $branch . chr(10);
                        $message .= 'Airline : ' . $airline->id . chr(10);
                        $message .= 'IP : ' . getIP() . chr(10);
                        $message .= 'Time : ' . Carbon::now() . chr(10) . '.';
                        Functions::sendAirlineCreditMessages($airline->id, $message);
                    } else {
                        Visa::addSystemReport($data);
                        $arr = [
                            'Status' => false,
                            'Message' => $data,
                        ];
                    }
                } else {
                    Visa::addSystemReport($data);
                    $arr = [
                        'Status' => false,
                        'Message' => $data,
                    ];
                }
            }
            return ['Data' => $arr, 'Result' => $data];
        } elseif ($method == 'ticket_information' and $data) {
            if (isset($data['TicketNo']) && $data['TicketNo']) {
                $ageCode = trim($data['PAX']);
                switch ($ageCode) {
                    case 'AD':
                        $ageCategory = 'Adult';
                        break;
                    case 'CH':
                        $ageCategory = 'Child';
                        break;
                    case 'IN':
                        $ageCategory = 'Infant';
                        break;
                }
                $taxAmount = 0;
                foreach ($data['TAXES'] as $tax) {
                    $taxAmount += $tax['TaxAmount'];
                }
                if (is_numeric(substr($data['PassengerFullName'], 0, 1))) {
                    if (str_contains($data['PassengerFullName'], ' ')) {
                        $sepratedFullName = explode(' ', $data['PassengerFullName']);
                        $sepratedName = explode('/', $sepratedFullName[1]);
                        $gender = substr($sepratedName[1], -2);
                        if ($gender == 'MR' or $gender == 'MS') {
                            $sepratedName[1] = substr($sepratedName[1], 0, -2);
                        } else {
                            $gender = substr($sepratedName[1], -3);
                            if ($gender == 'MRS') {
                                $sepratedName[1] = substr($sepratedName[1], 0, -3);
                            }
                        }
                    }
                } elseif (str_contains($data['PassengerFullName'], ' ')) {
                    $sepratedFullName = explode(' ', $data['PassengerFullName']);
                    $sepratedName = explode('/', $sepratedFullName[0]);
                    $gender = $sepratedFullName[1];
                } else {
                    $sepratedName = explode('/', $data['PassengerFullName']);
                    $gender = substr($sepratedName[1], -2);
                    if ($gender == 'MR' or $gender == 'MS') {
                        $sepratedName[1] = substr($sepratedName[1], 0, -2);
                    } else {
                        $gender = substr($sepratedName[1], -3);
                        if ($gender == 'MRS') {
                            $sepratedName[1] = substr($sepratedName[1], 0, -3);
                        }
                    }
                }
                $ticketInformation = [
                    'TicketNumber' => $data['TicketNo'],
                    'FirstName' => $sepratedName[1],
                    'LastName' => $sepratedName[0],
                    'Gender' => ($gender == 'MR') ? 'Male' : 'Female',
                    'AgeTitle' => $ageCategory,
                    'Inbound' => [
                        'FlightRoute' => ($data['COUPONS'][0]['JourneyType'] == 'Domestic') ? 'Internal' : 'International',
                        'FlightNumber' => $data['COUPONS'][0]['FlightNo'],
                        'PNR' => $data['COUPONS'][0]['PNR'],
                        'Origin' => [
                            'Iata' => self::getDetails('airport', $data['COUPONS'][0]['Origin'], true),
                            'Terminal' => false
                        ],
                        'Destination' => [
                            'Iata' => self::getDetails('airport', $data['COUPONS'][0]['Destination'], true),
                            'Terminal' => false
                        ],
                        'DepartureDateTime' => $data['COUPONS'][0]['Departure'],
                        'Remarks' => $data['History'][0]['Remark'],
                    ],
                    'Financial' => [
                        'PriceAdditions' => [
                            'Citizens' => false,
                        ],
                        'CommissionPaid' => [
                            'Percentage' => false,
                            'Transaction' => false,
                            'MembershipRight' => false,
                        ],
                        $ageCategory => [
                            'BaseFare' => $data['Fare'],
                            'Tax' => $taxAmount,
                            'Markup' => false,
                            'TotalFare' => $data['TotalPrice'],
                            'Payable' => ((int)$data['TotalPrice'] - (int)$data['Comission']),
                            'Commission' => [
                                'Percentage' => false,
                                'Final' => $data['Comission'],
                                'Price' => $data['Comission']
                            ]
                        ]
                    ],
                ];
                if (isset($data['History'][1]) && isset($data['COUPONS'][1])) {
                    $ticketInformation['Outbound'] = [
                        'FlightRoute' => ($data['COUPONS'][1]['JourneyType'] == 'Domestic') ? 'Internal' : 'International',
                        'FlightNumber' => $data['COUPONS'][1]['FlightNo'],
                        'PNR' => $data['COUPONS'][1]['PNR'],
                        'Origin' => [
                            'Iata' => self::getDetails('airport', $data['COUPONS'][1]['Origin'], true),
                            'Terminal' => false
                        ],
                        'Destination' => [
                            'Iata' => self::getDetails('airport', $data['COUPONS'][1]['Destination'], true),
                            'Terminal' => false
                        ],
                        'DepartureDateTime' => $data['COUPONS'][1]['Departure'],
                        'Remarks' => $data['History'][1]['Remark'],
                    ];
                }
                if ($data['History'][0]['Status'] == 'R') {
                    $status = 'refunded';
                } elseif ($data['History'][0]['Status'] == 'I' || $data['History'][0]['Status'] == 'O') {
                    $status = 'Booked';
                } elseif ($data['History'][0]['Status'] == 'F') {
                    $status = 'done';
                } elseif ($data['History'][0]['Status'] == 'S') {
                    $status = 'suspended';
                } else {
                    $status = 'indeterminate';
                }
                $ticketInformation['Status'] = $status;
                $ticketInformation['StatusCode'] = $data['History'][0]['Status'];
                return $ticketInformation;
            } else {
                return $data;
            }
        } elseif ($method == 'cancel_seat' and $data) {
            return $data;
        } elseif ($method == 'penalty' and $data) {
            return $data;
        } elseif ($method == 'penalty_now' and $data) {
            return $data;
        } elseif ($method == 'ticket_refund' and $data) {
            return $data;
        } elseif ($method == 'command' and $data) {
            return $data;
        } elseif ($method == 'CurrentBalance' and $data) {
            $data = json_decode($data, true);
            $data = str_replace(['CREDIT: ', ','], '', $data['AirNRSCommand']['Response']);

            $data = explode('IRR', $data);
            $arr = [
                'Status' => true,
                'Time' => time(),
                'CurrencyCode' => 'IRR',
                'Service' => 'nira',
                'Information' => (int)trim($data[0])
            ];
            return $arr;
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

    static function getDetails($action, $data, $details = false, $branch = false, $airline = false)
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
                $iataAirline = DB::table('airlines')->select('id')->where('iata', $data)->orWhere('icao', $data)->first();
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
                if ($branch != 2) {
                    $supplierApi = DB::table('mapping_colleagues')
                        ->select('colleague')
                        ->where('airplus', $data)
                        ->where('status', 1)
                        ->first();
                    if (is_null($supplierApi)) $supplierApi = (object)["colleague" => 1];
                } else {
                    $supplierApi = (object)["colleague" => $airline ? $airline['object'] : 1];
                }
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

    static function getRefundedTicketData($data)
    {
        preg_match('/REFUND DATE\s*([\d]{2}[A-Z]{3}[\d]{4}\s[\d]{4})/', $data, $refundDate);
        preg_match('/SALES FARE\s+([\d,.]+)/', $data, $salesFare);
        preg_match('/REFUNDED FARE\s+([\d,.]+)/', $data, $refundedFare);
        preg_match('/REFUNDED TAXES\s+([\d,.]+)/', $data, $refundedTaxes);
        preg_match('/PENALTY\s+([\d,.]+)/', $data, $penalty);
        preg_match('/REFUNDED AMOUNT\s+([\d,.]+)/', $data, $refundedAmount);

        $refundData = [
            'RefundDate' => trim($refundDate[1] ?? ''),
            'SalesFare' => (int) str_replace(',', '', $salesFare[1] ?? 0),
            'RefundedFare' => (int) str_replace(',', '', $refundedFare[1] ?? 0),
            'RefundedTaxes' => (int) str_replace(',', '', $refundedTaxes[1] ?? 0),
            'Penalty' => (int) str_replace(',', '', $penalty[1] ?? 0),
            'RefundedAmount' => (int) str_replace(',', '', $refundedAmount[1] ?? 0)
        ];

        return $refundData;
    }
}
