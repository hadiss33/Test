<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Ravis\V1;

use App\Http\Controllers\Api\Panel\V2\StaticController;
use App\Jobs\TemporaryReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RavisApi
{
    public $baseBranch = false;
    public static $isHub = false;
    public static $isSalesChannels = false;

    static function sendRequestFlight($request, array $data = ["Data"], $details = false, $method = 'POST', $branch = false)
    {
        if (!isset($data["Data"])) $data["Data"] = $data;
        $Method = [
            // Get Transaction
            'Transaction' => env('RAVIS_TRANSACTION'),

            // Availability
            'FlightList' => 'http://flight.ravisservice.ir/api/flights/ravisFlightList', // دریافت لیست پرواز
            'CurrentBalance' => 'https://ravisservice.ir/api/flights/v2/ravisCredit', // دریافت اعتبار
            'Lock' => 'https://ravisservice.ir/api/flights/v2/ravisReserve', // رزرو موقت پرواز
            'GetLock' => 'https://ravisservice.ir/api/flights/v2/ravisReserveProperty', // دریافت اطلاعات رزرو موقت پرواز
            'ReleaseLock' => 'https://ravisservice.ir/api/flights/v2/ravisReserveCancel', // کنسلی رزرو موقت پرواز
            'Book' => 'https://ravisservice.ir/api/flights/v2/ravisBook', // رزرو قطعی پرواز
            'RetriveFlight' => 'https://ravisservice.ir/api/flights/v2/ravisFlightLastData', // آخرین وضعیت پرواز
            'GetReference' => 'https://ravisservice.ir/api/flights/v2/ravisRefrenceList', // دریافت رفرنس پرواز
            'TravelAgencies' => 'https://ravisservice.ir/api/flights/v2/ravisTravelAgencies', // لیست آژانس های طرف قرارداد راویس
            'FastBook' => 'https://ravisservice.ir/api/flights/v2/ravisBookFast', // رزرو و صدور بلیط سریع
            'ReBook' => 'https://ravisservice.ir/api/flights/v2/ravisBookAgain', // بررسی مجدد در صورت بروز خطا در هنگام صدور بلیط
            'ReportRetrives' => 'https://ravisservice.ir/api/flights/v2/ravisReport', // گزارشات فروش و استرداد
            'GetPenalty' => 'https://ravisservice.ir/api/flights/v2/ravisViewFinePrice', // دریافت جریمه استرداد
            'DoRefund' => 'https://ravisservice.ir/api/flights/v2/ravisCancel', // استرداد بلیط
        ];

        if ($branch) {
            if (strpos($branch, 'b2c-') !== false) {
                $branch = str_replace('b2c-', '', $branch);
                self::$isSalesChannels = true;
            } else if (strpos($branch, 'b2b-') !== false) {
                $branch = str_replace('b2b-', '', $branch);
                self::$isSalesChannels = true;
            }
            $ravisApi = DB::table('application_interface')
                ->where('branch', $branch)
                ->where('object_type', 'colleague')
                ->where('status', 1)
                ->where('type', 'api')
                ->where('service', 'ravis')
                ->first();
            if (!$ravisApi) {
                $tempBranch = DB::table('offices')->select('type', 'base_online')->where('id', $branch)->first();
                if (!is_null($tempBranch->base_online) && $tempBranch->base_online == 1) {
                    $ravisApi = DB::table('application_interface')
                        ->where('branch', 1)
                        ->where('object_type', 'colleague')
                        ->where('status', 1)
                        ->where('type', 'api')
                        ->where('service', 'ravis')
                        ->first();
                    if (!$ravisApi) {
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
        } else {
            $ravisApi = DB::table('application_interface')->where('object_type', 'colleague')->where('status', 1)->where('type', 'api')->where('service', 'ravis')->first();
        }

        if ($ravisApi) {
            $param = ['CustomerId' => $ravisApi->username]; //env('RAVIS_COUSTOMER_ID')

            if ($data && $data["Data"]) {
                foreach ($data["Data"] as $key => $value) $param[$key] = $value;
            }

            if ($method == 'POST' and $request == 'FlightList') {
                try {
                    $result = Http::post($Method[$request], $param)->throw()->json();
                } catch (Throwable $e) {
                    return [
                        "Data" => [
                            [
                                'Status' => false,
                                'Code' => '2000-' . $e->getCode(),
                                'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                                'Trace' => $e->getTrace()
                            ]
                        ]
                    ];
                }
                if (count($result) > 0) { // در صورتی که پروازی در راویس تحت هر عنوانی وجود نداشته باشد آرایه خانه های پروازی را خالی بر میگرداند
                    foreach ($result as $item) {
                        $return[$item['FlightNo']][] = $item;
                    }
                    $return = $result;
                } else $return = false;
                $result = RavisAPI::resultAPI2ResultSys('flight', $request, $return, $details, null, $branch, $ravisApi);
            } else if ($request == 'Transaction') {
                $result = RavisAPI::resultAPI2ResultSys('flight', $request, $Method[$request], $details, null, $branch);
            } else if ($request == 'CurrentBalance' || $request == 'Book' || $request == 'FastBook') {
                if ($request == 'Book' || $request == 'FastBook') {
                    try {
                        $resultApi = Http::post($Method[$request], $param)->throw()->json();
                        if ($resultApi['Status'] == 'Ok') {
                            $result = RavisAPI::resultAPI2ResultSys('flight', $request, $resultApi, $details, (isset($data['SubData'])) ? $data['SubData'] : null, $branch);
                            if (isset($data['SubData'])) {
                                TemporaryReservation::dispatch([
                                    "id" => $data['SubData']['lockId'],
                                    "key" => 'url',
                                    "value" => $Method[$request]
                                ])->delay(now()->addMinutes(10))->onQueue('snailJob');
                                TemporaryReservation::dispatch([
                                    "id" => $data['SubData']['lockId'],
                                    "key" => 'reservation_request',
                                    "value" => $param
                                ])->delay(now()->addMinutes(10))->onQueue('snailJob');
                                TemporaryReservation::dispatch([
                                    "id" => $data['SubData']['lockId'],
                                    "key" => 'reservation',
                                    "value" => $resultApi
                                ])->delay(now()->addMinutes(10))->onQueue('snailJob');
                            }
                        } else if ((!isset($resultApi['Status']) || $resultApi['Status'] != 'Ok') && !isset($param['ReqNo'])) {
                            return [
                                "Data" => [[
                                    'Status' => false,
                                    'Code' => '1501-' . (isset($resultApi['ErrorCode'])) ? $resultApi['ErrorCode'] : null,
                                    'Message' => (isset($resultApi['ErrorMessage'])) ? ($resultApi['ErrorMessage'] != '' ? $resultApi['ErrorMessage'] : 'خطای ناشناخته از تامین کننده رخ داده است.') : null
                                ]]
                            ];
                        } else {
                            $resultAgainApi = Http::post($Method['ReBook'], $param)->throw()->json();
                            if ($resultAgainApi['Status'] == 'Ok') {
                                $details['repeat'] = [
                                    "status" => true,
                                    "lastResult" => $resultApi['ErrorMessage'] ? $resultApi['ErrorMessage'] : false
                                ];
                                $result = RavisAPI::resultAPI2ResultSys('flight', $request, $resultAgainApi, $details, (isset($data['SubData'])) ? $data['SubData'] : null, $branch);
                            } else {
                                return [
                                    "Data" => [[
                                        'Status' => false,
                                        'Code' => '1501-' . (isset($resultAgainApi['ErrorCode'])) ? $resultAgainApi['ErrorCode'] : null,
                                        'Message' => (isset($resultAgainApi['ErrorMessage'])) ? ($resultAgainApi['ErrorMessage'] != '' ? $resultAgainApi['ErrorMessage'] : 'خطای ناشناخته از تامین کننده رخ داده است.') : null
                                    ]]
                                ];
                            }
                            if (isset($data['SubData'])) {
                                TemporaryReservation::dispatch([
                                    "id" => $data['SubData']['lockId'],
                                    "key" => 'url',
                                    "value" => $Method[$request]
                                ])->delay(now()->addMinutes(10))->onQueue('snailJob');
                                TemporaryReservation::dispatch([
                                    "id" => $data['SubData']['lockId'],
                                    "key" => 'reservation_request',
                                    "value" => $param
                                ])->delay(now()->addMinutes(10))->onQueue('snailJob');
                                TemporaryReservation::dispatch([
                                    "id" => $data['SubData']['lockId'],
                                    "key" => 'reservation',
                                    "value" => $resultApi
                                ])->delay(now()->addMinutes(10))->onQueue('snailJob');
                            }
                        }
                    } catch (Throwable $e) {
                        return [
                            "Data" => [[
                                'Status' => false,
                                'Code' => '2001-' . $e->getCode(),
                                'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                                'Trace' => $e->getTrace()
                            ]],
                        ];
                    }
                } else {
                    try {
                        $result = RavisAPI::resultAPI2ResultSys('flight', $request, Http::post($Method[$request], $param)->throw()->json(), (isset($data['SubData'])) ? $data['SubData'] : null, null, $branch);
                    } catch (Throwable $e) {
                        return [
                            "Data" => [[
                                'Status' => false,
                                'Code' => '2002-' . $e->getCode(),
                                'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                                'Trace' => $e->getTrace()
                            ]]
                        ];
                    }
                }
            } else if ($request == 'RetriveFlight') {
                try {
                    $result = ["Data" => Http::post($Method[$request], $param)->throw()->json()];
                } catch (Throwable $e) {
                    return [
                        "Data" => [
                            'Status' => false,
                            'Code' => '2003-' . $e->getCode(),
                            'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                            'Trace' => $e->getTrace()
                        ]
                    ];
                }
            } else if ($request == 'Lock') {
                try {
                    $result = Http::post($Method[$request], $param)->throw()->json();
                } catch (Throwable $e) {
                    return [
                        'Status' => false,
                        'Code' => '2005-' . $e->getCode(),
                        'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                        'Trace' => $e->getTrace()
                    ];
                }
            } else if ($method == 'GET') {
                try {
                    $result = RavisAPI::resultAPI2ResultSys('flight', $request, Http::get($Method[$request], $param)->throw()->json(), $details, null, $branch);
                } catch (Throwable $e) {
                    return [
                        "Data" => [[
                            'Status' => false,
                            'Code' => '2004-' . $e->getCode(),
                            'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                            'Trace' => $e->getTrace()
                        ]],
                    ];
                }
            } else {
                try {
                    $result = Http::post($Method[$request], $param)->throw()->json();
                } catch (Throwable $e) {
                    return [
                        "Data" => [[
                            'Status' => false,
                            'Code' => '2005-' . $e->getCode(),
                            'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                            'Trace' => $e->getTrace()
                        ]],
                    ];
                }
            }
            return (is_array($result)) ? $result : [
                "Data" => [
                    'Status' => false,
                    'Code' => '2006',
                    'Message' => ['Information' => 0]
                ],
            ];
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

    public function getErrorMsg($error)
    {
        $Error = [
            '100' => 'اعتبار سنجی پارامترهای ورودی',
            '101' => 'تعداد مسافرین صحیح ارسال نشده',
            '102' => 'ظرفیت تکمیل پرواز',
            '103' => 'خطای سیستم',
            '104' => 'کدملی صحیح نمی باشد',
            '105' => 'شناسه یا آی پی صحیح نمی باشد',
            '106' => 'اعتبار کافی نیست',
            '107' => 'کد آژانس معتبر نیست',
            '108' => 'رزرو انجام نشده است',
            '109' => 'لیست ارسال نشده است',
            '110' => 'شماره درخواست معتبر نیست',
            '111' => 'زرو کنسل شده است',
            '112' => 'نام سرپرست و مسافرین صحیح ارسال نشده است',
            '113' => 'گروه سنی مسافرین صحیح ارسال نشده است',
            '114' => 'جنسیت مسافرین صحیح وارد نشده است',
            '115' => 'موبایل و تلفن سرپرست صحیح ارسال نشده است',
            '116' => 'فروش نهایی انجام نشده است',
            '117' => 'اسامی مسافرین تکراری می باشد.',
        ];
        return $Error[$error];
    }

    static function getClassName($class, $details)
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
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
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
                $tempClass = 'Economy/Coach Discounted';
                $tempClassTitleFa = 'اکونومی';
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

    static function resultAPI2ResultSys($goal, $method, $data, $details = false, $subData = null, $branch = false, $serviceDetails = false)
    {
        if ($goal == 'flight' && $method == 'FlightList' and $data != 0) {
            foreach ($data as $item) {
                $Classes = [];
                //foreach ($item as $class) {
                if ($branch) {
                    if (!strpos($branch, 'b2c-')) {
                        $tempBranch = DB::table('offices')->select('type', 'base_online')->where('id', $branch)->first();
                        if (is_null($tempBranch->base_online))
                            $checkFinancial = true;
                        else
                            $checkFinancial = false;
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
                    $tempFinancial = [
                        'PriceAdditions' => [
                            'Citizens' => (isset($item['PriceCitizen'])) ? $item['PriceCitizen'] : 0,
                        ],
                        'CommissionPaid' => [
                            'Percentage' => false,
                            'Transaction' => env('RAVIS_TRANSACTION'),
                            'MembershipRight' => false,
                        ],
                        'Adult' => [
                            'BaseFare' => (int)$item['PriceView'],
                            'Tax' => false,
                            'Markup' => $markup,
                            'TotalFare' => (int)$item['PriceView'],
                            'Payable' => ((int)$item['PriceView'] + (($markup / 100) * (int)$item['PriceView'])),
                            'Commission' => [
                                'Percentage' => (int)str_replace(array(' ', '%'), '', $item['Srv']),
                                'Final' => (int)$item['SrvPriceFinal'],
                                'Price' => (int)$item['SrvPrice']
                            ]
                        ],
                        'Child' => [
                            'BaseFare' => (int)$item['PriceCHD'],
                            'Tax' => false,
                            'Markup' => $markup,
                            'TotalFare' => (int)$item['PriceCHD'],
                            'Payable' => ((int)$item['PriceCHD'] + (($markup / 100) * (int)$item['PriceCHD'])),
                            'Commission' => [
                                'Percentage' => false,
                                'Final' => (int)$item['SrvPriceFinalCHD'],
                                'Price' => (int)$item['SrvPriceCHD']
                            ]
                        ],
                        'Infant' => [
                            'BaseFare' => (int)$item['PriceINF'],
                            'Tax' => false,
                            'Markup' => $markup,
                            'TotalFare' => (int)$item['PriceINF'],
                            'Payable' => ((int)$item['PriceINF'] + (($markup / 100) * (int)$item['PriceINF'])),
                            'Commission' => [
                                'Percentage' => false,
                                'Final' => false,
                                'Price' => false
                            ]
                        ]
                    ];
                } else {
                    $tempFinancial = [
                        'PriceAdditions' => [
                            'Citizens' => (isset($item['PriceCitizen'])) ? $item['PriceCitizen'] : 0,
                        ],
                        'CommissionPaid' => [
                            'Percentage' => false,
                            'Transaction' => env('RAVIS_TRANSACTION'),
                            'MembershipRight' => false,
                        ],
                        'Adult' => [
                            'BaseFare' => (int)$item['PriceView'],
                            'Tax' => false,
                            'Markup' => 0,
                            'TotalFare' => (int)$item['PriceView'],
                            'Payable' => ((int)$item['PriceView'] - (int)$item['SrvPriceFinal']),
                            'Commission' => [
                                'Percentage' => (int)str_replace(array(' ', '%'), '', $item['Srv']),
                                'Final' => (int)$item['SrvPriceFinal'],
                                'Price' => (int)$item['SrvPrice']
                            ]
                        ],
                        'Child' => [
                            'BaseFare' => (int)$item['PriceCHD'],
                            'Tax' => false,
                            'Markup' => 0,
                            'TotalFare' => (int)$item['PriceCHD'],
                            'Payable' => ((int)$item['PriceCHD'] - (int)$item['SrvPriceFinalCHD']),
                            'Commission' => [
                                'Percentage' => false,
                                'Final' => (int)$item['SrvPriceFinalCHD'],
                                'Price' => (int)$item['SrvPriceCHD']
                            ]
                        ],
                        'Infant' => [
                            'BaseFare' => (int)$item['PriceINF'],
                            'Tax' => false,
                            'Markup' => 0,
                            'TotalFare' => (int)$item['PriceINF'],
                            'Payable' => (int)$item['PriceINF'],
                            'Commission' => [
                                'Percentage' => false,
                                'Final' => false,
                                'Price' => false
                            ]
                        ]
                    ];
                }
                $Classes[] = [
                    'FlightStatus' => true,
                    'Reservable' => ($item['Reservable'] == 1) ? true : false,
                    'Status' => ($item['Reservable'] == 1) ? 'reservable' : 'full',
                    'CancelationPolicy' => false,
                    'BookingPolicy' => false,
                    'Supplier' => RavisApi::getDetails('supplier', $item['KndSys'], $details, $branch),
                    'SystemSupplier' => RavisApi::getDetails('system_supplier', $item['KndSys'], $details, $branch),
                    'FlightId' => $item['ParvazId'],
                    'FareName' => $item['ClassId'],
                    'CabinType' => RavisApi::getClassName($item['Class'], $details),
                    'AvailableSeat' => $item['CapLast'],
                    'Rules' => false,
                    'Financial' => $tempFinancial,
                    'BaseData' => [
                        "Supplier" => [
                            'Supplier' => RavisApi::getDetails('supplier', $item['KndSys'], $details),
                            'SystemSupplier' => RavisApi::getDetails('system_supplier', $item['KndSys'], $details)
                        ],
                        'Financial' => [
                            'PriceAdditions' => [
                                'Citizens' => (isset($item['PriceCitizen'])) ? $item['PriceCitizen'] : 0,
                            ],
                            'CommissionPaid' => [
                                'Percentage' => false,
                                'Transaction' => env('RAVIS_TRANSACTION'),
                                'MembershipRight' => false,
                            ],
                            'Adult' => [
                                'BaseFare' => (int)$item['PriceView'],
                                'Tax' => false,
                                'Markup' => 0,
                                'TotalFare' => (int)$item['PriceView'],
                                'Payable' => ((int)$item['PriceView'] - (int)$item['SrvPriceFinal']),
                                'Commission' => [
                                    'Percentage' => (int)str_replace(array(' ', '%'), '', $item['Srv']),
                                    'Final' => (int)$item['SrvPriceFinal'],
                                    'Price' => (int)$item['SrvPrice']
                                ]
                            ],
                            'Child' => [
                                'BaseFare' => (int)$item['PriceCHD'],
                                'Tax' => false,
                                'Markup' => 0,
                                'TotalFare' => (int)$item['PriceCHD'],
                                'Payable' => ((int)$item['PriceCHD'] - (int)$item['SrvPriceFinalCHD']),
                                'Commission' => [
                                    'Percentage' => false,
                                    'Final' => (int)$item['SrvPriceFinalCHD'],
                                    'Price' => (int)$item['SrvPriceCHD']
                                ]
                            ],
                            'Infant' => [
                                'BaseFare' => (int)$item['PriceINF'],
                                'Tax' => false,
                                'Markup' => 0,
                                'TotalFare' => (int)$item['PriceINF'],
                                'Payable' => (int)$item['PriceINF'],
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
                                'Number' => isset($item['FreeBag']) && $item['FreeBag'] != '' ? 1 : false,
                                'TotalWeight' => isset($item['FreeBag']) && $item['FreeBag'] != '' ? $item['FreeBag'] : false,
                            ],
                            'Hand' => [
                                'Number' => false,
                                'TotalWeight' => false,
                            ]
                        ],
                        'Child' => [
                            'Trunk' => [
                                'Number' => false,
                                'TotalWeight' => false,
                            ],
                            'Hand' => [
                                'Number' => false,
                                'TotalWeight' => false,
                            ]
                        ],
                        'Infant' => [
                            'Trunk' => [
                                'Number' => false,
                                'TotalWeight' => false,
                            ],
                            'Hand' => [
                                'Number' => false,
                                'TotalWeight' => false,
                            ]
                        ]
                    ],
                    'Remarks' => [
                        'Inbound' => [
                            'AirlineSupplier' => false,
                            'TourRequirement' => ($item['OnlyTour'] == 1) ? $item['OnlyTour'] : false,
                            'OneWayRequirement' => false,
                            'RoundtripRequirement' => ($item['OnlyTwoWay'] == 1) ? $item['OnlyTwoWay'] : false,
                            'PhoneRequirement' => ($item['OnlyPhone'] == 1) ? $item['OnlyPhone'] : false,
                            'FlightReturn' => (!is_null($item['FlightNoBack'])) ? true : false,
                            'DateReturn' => (!is_null($item['FlightDateBack'])) ? true : false,
                            'Description' => (!is_null($item['DscFlight'])) ? true : false,
                            'Special' => false,
                            'Warranty' => false,
                        ],
                        "Outbound" => false
                    ]
                ];
                //}
                $Flights[] = [
                    'Service' => 'ravis',
                    'ServiceId' => is_object($serviceDetails) ? $serviceDetails->id : false,
                    'ServiceBranch' => is_object($serviceDetails) ? $serviceDetails->branch : false,
                    'DisplayableService' => self::$isHub ? 'airplusHub' : 'ravis',
                    'Verified' => ($item['KndSys'] == 90 ? true : false),
                    'FlightType' => 'Charter',
                    'FlightRoute' => ($item['FlightType'] == 2) ? 'International' : 'Internal',
                    'FlightNumber' => $item['FlightNo'],
                    'Origin' => [
                        'Iata' => RavisApi::getDetails('airport', $item['IataCodSource'], $details),
                        'Terminal' => (!is_null($item['Terminal'])) ? true : false
                    ],
                    'Destination' => [
                        'Iata' => RavisApi::getDetails('airport', $item['IataCodDestinate'], $details),
                        'Terminal' => false
                    ],
                    'DepartureDateTime' => date('Y-m-d H:i:s', strtotime($item['FlightDateTime'])),
                    'ArrivalDateTime' => false,
                    'Duration' => false,
                    'Aircraft' => RavisApi::getDetails('aircraft', $item['AirPlaneName'], $details),
                    'Airline' => RavisApi::getDetails('airline', $item['AirlineCode'], $details),
                    'Remarks' => [
                        'AirlineSupplier' => false,
                        'TourRequirement' => ($item['OnlyTour'] == 1) ? $item['OnlyTour'] : false,
                        'OneWayRequirement' => false,
                        'RoundtripRequirement' => ($item['OnlyTwoWay'] == 1) ? $item['OnlyTwoWay'] : false,
                        'PhoneRequirement' => ($item['OnlyPhone'] == 1) ? $item['OnlyPhone'] : false,
                        'FlightReturn' => (!is_null($item['FlightNoBack'])) ? true : false,
                        'DateReturn' => (!is_null($item['FlightDateBack'])) ? true : false,
                        'Description' => (!is_null($item['DscFlight'])) ? true : false,
                        'Special' => false,
                        'Warranty' => false,
                    ],
                    'ReturningFlight' => ($item['OnlyTwoWay'] == 1) ? [
                        'AllowedReturnFlights' => [
                            'FlightNumber' => (!is_null($item['FlightNoBack'])) ? true : false,
                            'FlightDate' => (!is_null($item['FlightDateBack'])) ? true : false,
                        ],
                        'UnauthorizedReturnFlights' => false,
                        'ReturnOnlyFromTheAirlineOfOrigin' => false,
                        'ReturnFlightProvider' => false,
                        'DistanceToReturnFlight' => [
                            'Min' => 0,
                            'Max' => 0,
                        ],
                    ] : false,
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
                    'Message' => $data
                ];
            }
            return ["Data" => $arr, 'Result' => $data];
        } else if ($goal == 'flight' && ($method == 'Book' || $method == 'FastBook') and !is_null($data) && !is_null($subData)) {
            if ($data['Status'] == 'Ok') {
                foreach ($data['Result'] as $item) {
                    $returnArray = [
                        'Status' => true,
                        'DepartureSegment' => [
                            'PNR' => [
                                'Service' => $item['Pnr'],
                                'Original' => $item['ReqPNR']
                            ],
                            'Origin' => [
                                'Iata' => $subData['origin']['iata'],
                                'Terminal' => $subData['origin']['terminal']
                            ],
                            'Destination' => [
                                'Iata' => $subData['destination']['iata'],
                                'Terminal' => $subData['destination']['terminal']
                            ],
                            'FlightDateTime' => $subData['departureDateTime'],
                            'LocalTicketNumber' => null,
                            'OriginalTicketNumber' => $item['TicketNo'],
                            'FlightNumber' => $item['FlightNo'],
                            'Description' => trim($item['DscTicket1'] . ' ' . $item['DscTicket2'] . ' ' . $item['DscTicket3'] . ' ' . $item['DscTicket4'] . ' ' . $item['DscTicket5'] . ' ' . $item['DscTicket6'] . ' '),
                        ],
                        'ReturningSegment' => false,
                    ];
                    if (isset($details['repeat'])) {
                        $returnArray['Repeat'] = $details['repeat'];
                    }
                    $arr[] = $returnArray;
                }
            } else {

                $arr = [
                    'Status' => false,
                    'Time' => time(),
                    'Code' => '1501-' . (isset($data['ErrorCode'])) ? $data['ErrorCode'] : null,
                    'Message' => (isset($data['ErrorMessage'])) ? ($data['ErrorMessage'] != '' ? $data['ErrorMessage'] : 'خطای ناشناخته از تامین کننده رخ داده است.') : null
                ];
            }
            return ["Data" => $arr, 'Result' => $data];
        } else if ($method == 'CurrentBalance' and $data != 0) {
            $arr = [
                'Status' => true,
                'Time' => time(),
                'CurrencyCode' => 'IRR',
                'Information' => $data['Result']
            ];
            return $arr;
        }
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
                $supplierApi = DB::table('mapping_colleagues')
                    ->select('colleague')
                    ->where('ravis', $data)
                    ->where('status', 1)
                    ->first();

                if ((is_null($supplierApi) || self::$isHub) && $branch != 2) {
                    $supplierApi = (object)["colleague" => 1];
                } else if ((is_null($supplierApi) || self::$isHub) && $branch == 2) {
                    $supplierApi = (object)["colleague" => 36];
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
                $supplier['base_id'] = $data;
                return $supplier;
            } else if ($action == 'system_supplier') {
                $supplierApi = DB::table('mapping_colleagues')
                    ->select('colleague')
                    ->where('ravis', $data)
                    ->where('status', 1)
                    ->first();

                if (is_null($supplierApi)) $supplierApi = (object)["colleague" => 0];
                $supplier = json_decode(Redis::get('colleagues:' . $supplierApi->colleague), true);
                if (!$supplier) {
                    $supplier = DB::table('colleagues')->select('id', 'office as title_fa', 'office as title_en', 'first_name', 'last_name', 'credit_amount', 'status')->where('id', $supplierApi->colleague)->first();
                    $supplier = (array) $supplier;
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
                $supplier['base_id'] = $data;
                return $supplier;
            }
        }
    }
}
