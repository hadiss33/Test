<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V1;

use App\Http\Controllers\Api\Panel\V2\StaticController;
use App\Jobs\TemporaryReservation;
use App\Lib\Auxiliary\Visa;
use App\Models\Airport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class SepehrApi
{
    public static $isHub = false;

    static function sendRequest($request, array $data = [], $method = 'POST', $suppliers = [95], $branch = false)
    {
        $Method = [
            // Get Transaction
            'Transaction' => env('SEPEHR_TRANSACTION'),

            // Event Retrieval B2M
            'EventRetrievalB2M' => '/api/ThirdParties/EventRetrieval/B2M/V1/GetEvents', // دریافت Event های سیستم فروش و رزرواسیون - بر اساس اطلاعات دفتر مرکزی

            // Event Retrieval B2B
            'EventRetrievalB2B' => '/api/ThirdParties/EventRetrieval/B2B/V1/GetEvents', // دریافت Event های سیستم فروش و رزرواسیون - سیستم یک تامین کننده

            // Deep link
            'FlightDeepLink' => '/api/DeepLink/Flight/Availability/Oneway/SpecificFlight/V1', // یجاد deep link روی یک پرواز خاص، می توان از این متد استفاده کرد. پس از اسال پارامترهای لازم به این متد، سیستم سپهر کاربر را به آن پرواز هدایت خواهد کرد

            // CurrentBalance
            'CurrentBalance' => '/api/Partners/Generic/V7/CurrentBalance', // دریافت باقی مانده اعتبار و همچنین مهلت پرداخت

            //
            'GetActiveRoutes' => '/api/Partners/Flight/Availability/V16/GetActiveRoutes', // دریافت باقی مانده اعتبار و همچنین مهلت پرداخت

        ];

        $tempApi = DB::table('application_interface')->where('object_type', 'colleague')->where('object', $suppliers[0])->where('status', 1)->where('type', 'api')->where('service', 'sepehr')->first();
        if (!is_null($tempApi)) {
            $param = ['Username' => $tempApi->username, 'Password' => md5($tempApi->password)];

            foreach ($data as $key => $value) $param[$key] = $value;
            if ($method == 'POST' and $request != 'GetContent') {
                try { // انجام عملیات
                    return SepehrApi::resultAPI2ResultSys('GetActiveRoutes', $request, Http::post($tempApi->url . $Method[$request], $param)->throw()->json(), null, false, $branch);
                } catch (Throwable $e) {
                    return [
                        'Data' => [
                            'Status' => false,
                            'Code' => '2000-' . $e->getCode(),
                            'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.'
                        ],
                        'Trace' => $e->getTrace()
                    ];
                }
            } elseif ($method == 'GET' and $request == 'GetContent') {
                return Http::get('https://SepehrContentCenter.ir' . $Method[$request], $param)->throw()->json();
            } elseif ($request == 'Transaction') {
                return $Method[$request];
            } else {
                return SepehrApi::resultAPI2ResultSys('flight', $request, Http::get(env('SEPEHR_HOST') . $Method[$request], $param)->throw()->json(), null, false, $branch);
            }
        }
    }

    static function sendRequestFlight($request, array $data = ['Data'], $details = false, $method = 'POST', $suppliers = false, $branch = false) // 190 Test
    {
        if (!$suppliers) {
            $suppliers = DB::table('application_interface')
                ->where('object_type', 'colleague')
                ->where(function ($q) use ($request, $branch) {
                    if ($request != 'GetActiveRoutes') {
                        $q->where('branch', $branch);
                    }
                })
                ->where('status', 1)
                ->where('type', 'api')
                ->where('service', 'sepehr')
                ->get();
            if (count($suppliers) == 0) {
                if (str_contains($branch, 'b2c') || str_contains($branch, 'b2b')) {
                    $branch = explode('-', $branch)[1];
                }
                $tempBranch = DB::table('offices')->select('type', 'base_online')->where('id', $branch)->first();
                if (!is_null($tempBranch->base_online) && $tempBranch->base_online == 1) {
                    $suppliers = DB::table('application_interface')
                        ->where('branch', 1)
                        ->where('object_type', 'colleague')
                        ->where('status', 1)
                        ->where('type', 'api')
                        ->where('service', 'sepehr')
                        ->get();
                    if (!$suppliers) {
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
        }

        $Method = [
            // Availability
            'SearchByRouteAndDate' => '/api/Partners/Flight/Availability/V12/SearchByRouteAndDate', // دریافت اطلاعات ظرفیت و نرخ پروازها براساس مسیر و تاریخ
            'GetByDateRangeWebservice_1' => '/api/Partners/Flight/Availability/V12/DateRange/Webservice/GetRange1', // بدست آوردن پروازهای وب سرویسی براساس بازه تاریخی
            'GetByDateRangeWebservice_2' => '/api/Partners/Flight/Availability/V12/DateRange/Webservice/GetRange2', // بدست آوردن پروازهای وب سرویسی براساس بازه تاریخی
            'GetByDateRangeWebservice_3' => '/api/Partners/Flight/Availability/V12/DateRange/Webservice/GetRange3', // بدست آوردن پروازهای وب سرویسی براساس بازه تاریخی
            'GetByDateRangeWebservice_4' => '/api/Partners/Flight/Availability/V12/DateRange/Webservice/GetRange4', // بدست آوردن پروازهای وب سرویسی براساس بازه تاریخی
            'GetByDateRangeWebservice_5' => '/api/Partners/Flight/Availability/V12/DateRange/Webservice/GetRange5', // بدست آوردن پروازهای وب سرویسی براساس بازه تاریخی
            'GetByDateRangeCharter' => '/api/Partners/Flight/Availability/V12/DateRange/GetCharterFlights', // بدست آوردن پروازهای وب سرویسی براساس بازه تاریخی
            'GetFlightCount' => '/api/Partners/Flight/Availability/V12/DateRange/GetFlightCount', // بدست آوردن تعداد کل پروازهای چارتری و وب سرویسی یک تامین کننده
            'GetActiveRoutesCharter' => '/api/Partners/Flight/Availability/V12/ActiveRoutes/GetCharterActiveRoutes', // دریافت مسیرهای فعالی که تامین کننده روی آنها دارای سهمیه اختصاصی (چارتر) می باشد
            'GetActiveRoutesWebservice' => '/api/Partners/Flight/Availability/V12/ActiveRoutes/GetWebserviceActiveRoutes', // دریافت مسیرهای فعالی که تامین کننده روی آنها دارای پرواز وب سرویسی می باشد
            'GetActiveRoutes' => '/api/Partners/Flight/Availability/V16/GetActiveRoutes', // دریافت باقی مانده اعتبار و همچنین مهلت پرداخت

            // Boking
            'Lock' => '/api/Partners/Flight/Booking/V10/Lock', // قفل کردن ظرفیت و نرخ
            'Book' => '/api/Partners/Flight/Booking/V10/Book', //  انجام قطعی رزرو
            'ReleaseLock' => '/api/Partners/Flight/Booking/V9/ReleaseLock', // برای آزاد کردن قفل

            // Refund
            'RetriveBooking' => '/api/Partners/Flight/Refund/V3/RetrieveBooking', // بدست آوردن اطلاعات مسافران یک رزرو
            'GetPenalty' => '/api/Partners/Flight/Refund/V3/GetPenalty', // جزییات مربوط به جریمه مسافران انتخاب شده را بر می گرداند. و کاربرد آن فقط برای زمانی هست که دلیل استرداد "دلایل شخصی مسافر" بوده باشد. بنابراین برای حالتی که دلیل استرداد "تغییر برنامه پرواز توسط ایرلاین" باشد به دلیل صفر بودن جریمه، این متد کاربرد نخواهد داشت.
            'DoRefund' => '/api/Partners/Flight/Refund/V3/DoRefund', // استراد یک رزرو
            'RefundGetStatus' => '/api/Partners/Flight/Refund/V3/GetStatus', // استعلام وضعیت استردادهایی مانند "استرداد به دلیل بدی آب و هوا" یا NoShow که نیاز به تایید کارشناس پرواز دارند

            // Changed Schedule
            'ChangedSchedulePassengersCharter' => '/api/Partners/ChangedSchedulePassengers/V1/Charter/Get', // یست رزروهایی که تغییری در برنامه پروازی مسافران آنها داده شده است
            'ChangedSchedulePassengersWebservice' => '/api/Partners/ChangedSchedulePassengers/V1/Webservice/Get', // یست رزروهایی که تغییری در برنامه پروازی مسافران آنها داده شده است

            // Retrieve Booking
            'BookGetStatus' => '/api/Partners/Flight/RetrieveBooking/V1/GetStatus', // زمانی که درخواست Book به سیستم سپهر ارسال نموده اید، ولی به هر دلیلی - مانند مشکلات شبکه و قطعی اینترنت - جوابی به درست شما نرسیده است، با فرخوانی این متد می توانید از وضعیت درخواست خود و اینکه آیا صادر شده است یا خیر اطلاعات کسب نمایید.
            'BookGetHistory' => '/api/Partners/Flight/RetrieveBooking/V1/GetHistory', // دریافت کلیه اطلاعات مربوط به یک رزرو
        ];

        if ($request == 'Transaction') {
            return SepehrApi::resultAPI2ResultSys('flight', $request, $Method[$request], $details, false, $branch);
        } else if ($request == 'CurrentBalance' || $request == 'SearchByRouteAndDate') {
            $array_get_api = [];
            foreach ($suppliers as $supplier) {
                $supplierObject = $supplier->object;
                if (!is_null($supplier->data)) {
                    $dataDecode = json_decode($supplier->data, true);
                    if (isset($dataDecode['nira']) && $dataDecode['nira']) {
                        $data['Data']['FetchSupplierWebserviceFlights'] = true;
                    }
                }
                $origin = DB::table('airports')->select('id')->where('iata', $data['Data']['OriginIataCode'])->first();
                $destination = DB::table('airports')->select('id')->where('iata', $data['Data']['DestinationIataCode'])->first();
                $keyDay = strtolower(Carbon::parse($data['Data']['DepartureDate'])->englishDayOfWeek);
                $flightActiveRoute = DB::table('flight_active_route')
                    ->select('id')
                    ->where('colleague', $supplierObject)
                    ->where('origin', $origin->id)
                    ->where('destination', $destination->id)
                    ->where($keyDay, true)
                    ->first();
                if (!is_null($flightActiveRoute)) {
                    $get_api = '';
                    $array_get_api_charter = [];
                    $array_get_api_web_service = [];
                    $get_api_error = false;

                    $tempApi = DB::table('application_interface')->where('object_type', 'colleague')->where('object', $supplierObject)->where('status', 1)->where('type', 'api')->where('service', 'sepehr')->first();

                    if ($request == 'BookGetStatus' || $request == 'BookGetHistory') {
                        $param = ['Credential' => ['Username' => $tempApi->username, 'Password' => md5($tempApi->password)]];
                    } else {
                        $param = ['Username' => $tempApi->username, 'Password' => md5($tempApi->password)];
                    }
                    foreach ($data['Data'] as $key => $value) $param[$key] = $value;
                    try { // انجام عملیات
                        $get_api = Http::post($tempApi->url . $Method[$request], $param)->json();
                        if (isset($get_api['ErrorMessage'])) $get_api_error = ['Data' => Visa::addSystemReport(['supplier' => $supplierObject, 'Message' => $get_api['ErrorMessage'], 'Trace' => $get_api['ExceptionType']])];
                        else if (isset($get_api['Message'])) $get_api_error = ['Data' => Visa::addSystemReport(['supplier' => $supplierObject, 'Message' => $get_api['Message']])];
                    } catch (Throwable $e) {
                        $get_api_error = [
                            'Data' => [
                                'Status' => false,
                                'Code' => '2001-' . $e->getCode(),
                                'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.'
                            ],
                            'Trace' => $e->getTrace()
                        ];
                    }
                    if (isset($get_api['CharterFlights']) && count($get_api['CharterFlights']) > 0) {
                        foreach ($get_api['CharterFlights'] as $item) {
                            $item['SystemSupplier'] = $supplierObject;
                            $item['Nira'] = (isset(json_decode($tempApi->data)->nira) ? json_decode($tempApi->data)->nira : false);
                            $array_get_api_charter[] = $item;
                        }
                    }
                    if (isset($get_api['WebserviceFlights']) && count($get_api['WebserviceFlights']) > 0) {
                        foreach ($get_api['WebserviceFlights'] as $item) {
                            $item['SystemSupplier'] = $supplierObject;
                            $item['Nira'] = (isset(json_decode($tempApi->data)->nira) ? json_decode($tempApi->data)->nira : false);
                            $array_get_api_web_service[] = $item;
                        }
                    }

                    $isHub = false;
                    if ($supplier->branch == 1) {
                        $isHub = true;
                    }

                    if ($get_api_error) {
                        $array_get_api[] = [
                            'status' => false,
                            'serviceId' => $supplier->id,
                            'serviceBranch' => $supplier->branch,
                            'isHub' => $isHub,
                            'supplier' => $supplierObject,
                            'data' => $get_api_error
                        ];
                    } else {
                        $array_get_api[] = [
                            'status' => true,
                            'serviceId' => $supplier->id,
                            'serviceBranch' => $supplier->branch,
                            'isHub' => $isHub,
                            'supplier' => $supplierObject,
                            'CurrencyCode' => 'IRR',
                            'CharterFlights' => $array_get_api_charter,
                            'WebserviceFlights' => $array_get_api_web_service
                        ];
                    }
                }
            }
            return SepehrApi::resultAPI2ResultSys(
                'flight',
                $request,
                $array_get_api,
                (isset($data['SubData'])) ? $data['SubData'] : null,
                $details,
                $branch
            );
        } else if ($request == 'Book') {
            foreach ($suppliers as $supplier) {
                if (is_numeric($suppliers[0])) {
                    $tempApi = DB::table('application_interface')->where('object_type', 'colleague')->where('object', $suppliers[0])->where('status', 1)->where('type', 'api')->where('service', 'sepehr')->first();
                } else {
                    return [
                        "Data" => [
                            'Status' => false,
                            'Code' => '2007',
                            'Message' => ['Information' => 'Api Not Found!']
                        ]
                    ];
                }

                if ($request == 'BookGetStatus' || $request == 'BookGetHistory') {
                    $param = ['Credential' => ['Username' => $tempApi->username, 'Password' => md5($tempApi->password)]];
                } else {
                    $param = ['Username' => $tempApi->username, 'Password' => md5($tempApi->password)];
                }

                foreach ($data['Data'] as $key => $value) $param[$key] = $value;
                if (isset($data['SubData'])) {
                    TemporaryReservation::dispatch([
                        "id" => $data['SubData']['lockId'],
                        "key" => 'url',
                        "value" => $tempApi->url . $Method[$request]
                    ])->delay(now()->addMinutes(10))->onQueue('snailJob');
                    TemporaryReservation::dispatch([
                        "id" => $data['SubData']['lockId'],
                        "key" => 'reservation_request',
                        "value" => $param
                    ])->delay(now()->addMinutes(10))->onQueue('snailJob');
                }
                try { // انجام عملیات
                    $get_api = Http::timeout(60)->post($tempApi->url . $Method[$request], $param)->json();
                    if ($request == 'Book' && !isset($get_api['LocalPnr']) && isset($get_api['ErrorMessage'])) {
                        return [
                            'Data' => [[
                                'Status' => false,
                                'Code' => '1502-' . $get_api['ExceptionType'],
                                'Message' => $get_api['ErrorMessage']
                            ]]
                        ];
                    }
                    if (isset($data['SubData'])) {
                        TemporaryReservation::dispatch([
                            "id" => $data['SubData']['lockId'],
                            "key" => 'reservation',
                            "value" => $get_api
                        ])->delay(now()->addMinutes(10))->onQueue('snailJob');
                    }
                } catch (Throwable $e) {
                    $get_api = Http::timeout(60)->post($tempApi->url . $Method['BookGetStatus'], [
                        'Credential' => [
                            'Username' => $tempApi->username,
                            'Password' => md5($tempApi->password)
                        ],
                        "YourLocalInventoryPnr" => $param['YourLocalInventoryPnr']
                    ])->json();
                    if (isset($get_api['StatusId']) && $get_api['StatusId'] != 1) {
                        return [
                            'Data' => [[
                                'Status' => false,
                                'Code' => '1505-' . $get_api['StatusId'],
                                'Message' => $param['YourLocalInventoryPnr'] . ':' . $get_api['StatusDesc'] . ':Last Error:' . $e->getMessage() . (isset($get_api['FailReason']) ? ' | ' . $get_api['FailReason'] : '')
                            ]]
                        ];
                    } else if (!isset($get_api['LocalPnr']) && isset($get_api['ErrorMessage'])) {
                        return [
                            'Data' => [[
                                'Status' => false,
                                'Code' => '1504-' . $get_api['ExceptionType'],
                                'Message' => $get_api['ErrorMessage']
                            ]]
                        ];
                    } else {
                        return [
                            'Data' => [[
                                'Status' => false,
                                'Code' => '2002-' . $e->getCode(),
                                'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.'
                            ]],
                            'Trace' => $e->getTrace()
                        ];
                    }
                }
            }
            return SepehrApi::resultAPI2ResultSys(
                'flight',
                $request,
                $get_api,
                (isset($data['SubData'])) ? $data['SubData'] : null,
                false,
                $branch
            );
        } else if ($request == 'GetActiveRoutes') {
            $array_get_api = [];
            foreach ($suppliers as $supplier) {
                if (!is_null($supplier->data)) {
                    $dataDecode = json_decode($supplier->data, true);
                    if (isset($dataDecode['nira']) && $dataDecode['nira']) {
                        $data['Data']['FetchSupplierWebserviceFlights'] = true;
                    }
                }
                $get_api = '';
                $array_get_api_charter = [];
                $array_get_api_web_service = [];
                $get_api_error = false;

                $tempApi = DB::table('application_interface')->where('object_type', 'colleague')->where('object', $supplier->object)->where('status', 1)->where('type', 'api')->where('service', 'sepehr')->first();

                if ($request == 'BookGetStatus' || $request == 'BookGetHistory') {
                    $param = ['Credential' => ['Username' => $tempApi->username, 'Password' => md5($tempApi->password)]];
                } else {
                    $param = ['Username' => $tempApi->username, 'Password' => md5($tempApi->password)];
                }

                foreach ($data['Data'] as $key => $value) $param[$key] = $value;
                try { // انجام عملیات
                    $get_api = Http::post($tempApi->url . $Method[$request], $param)->json();
                    if (isset($get_api['ErrorMessage'])) $get_api_error = ['Data' => Visa::addSystemReport(['supplier' => $supplier->object, 'Message' => $get_api['ErrorMessage'], 'Trace' => $get_api['ExceptionType']])];
                    else if (isset($get_api['Message'])) $get_api_error = ['Data' => Visa::addSystemReport(['supplier' => $supplier->object, 'Message' => $get_api['Message']])];
                } catch (Throwable $e) {
                    $get_api_error = [
                        'Data' => [
                            'Status' => false,
                            'Code' => '2001-' . $e->getCode(),
                            'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.'
                        ],
                        'Trace' => $e->getTrace()
                    ];
                }

                if ($get_api_error) {
                    $array_get_api[] = [
                        'status' => false,
                        'supplier' => $supplier->object,
                        'data' => $get_api_error
                    ];
                } else {
                    $array_get_api[] = [
                        'status' => true,
                        'supplier' => $supplier->object,
                        'data' => $get_api,
                    ];
                    $unsubmitItems = [];
                    foreach ($get_api['ActiveRouteList'] as $item) {
                        $origin = DB::table('airports')->select('id')->where('iata', $item['OriginIataCode'])->first();
                        $destination = DB::table('airports')->select('id')->where('iata', $item['DestinationIataCode'])->first();
                        if (!is_null($origin) && !is_null($destination)) {
                            $itemForImportDataBase = [
                                "colleague" => $supplier->object,
                                "origin" => $origin->id,
                                "destination" => $destination->id,
                                "monday" => $item['Monday'],
                                "tuesday" => $item['Tuesday'],
                                "wednesday" => $item['Wednesday'],
                                "thursday" => $item['Thursday'],
                                "friday" => $item['Friday'],
                                "saturday" => $item['Saturday'],
                                "sunday" => $item['Sunday']
                            ];
                            $checkInsert = DB::table('flight_active_route')
                                ->where('colleague', $supplier->object)
                                ->where('origin', $origin->id)
                                ->where('destination', $destination->id)
                                ->first();
                            if (is_null($checkInsert)) {
                                DB::table('flight_active_route')->insert($itemForImportDataBase);
                            } else {
                                DB::table('flight_active_route')->where('id', $checkInsert->id)->update($itemForImportDataBase);
                            }
                        } else {
                            $unsubmitItems[] = $itemForImportDataBase = [
                                "colleague" => $supplier->object,
                                "origin" => $item['OriginIataCode'],
                                "destination" => $item['DestinationIataCode'],
                                "monday" => $item['Monday'],
                                "tuesday" => $item['Tuesday'],
                                "wednesday" => $item['Wednesday'],
                                "thursday" => $item['Thursday'],
                                "friday" => $item['Friday'],
                                "saturday" => $item['Saturday'],
                                "sunday" => $item['Sunday']
                            ];
                        }
                    }
                }
            }

            return [
                "unsubmit_items" => isset($unsubmitItems) ? $unsubmitItems : false
            ];
        } else {
            $tempApi = DB::table('application_interface')->where('object_type', 'colleague')->where('object', $suppliers[0])->where('status', 1)->where('type', 'api')->where('service', 'sepehr')->first();
            $param = ['Username' => $tempApi->username, 'Password' => md5($tempApi->password)];
            foreach ($data['Data'] as $key => $value) $param[$key] = $value;

            try {
                $result = Http::post($tempApi->url . $Method[$request], $param)->json();
                return [
                    'Data' => [[
                        'Status' => true,
                        'Result' => $result
                    ]]
                ];
            } catch (Throwable $e) {
                return [
                    'Data' => [[
                        'Status' => false,
                        'Code' => '2003-' . $e->getCode(),
                        'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                        'Trace' => $e->getTrace()
                    ]],
                ];
            }
        };
    }

    function sendRequestTour($request, array $data = ['Data'], $method = 'POST', $suppliers = [190], $branch = false)
    {
        $Method = [
            // Availability
            'GetContent' => '/api/Hotel/GetContent/V2', // این متد جهت دریافت اطلاعات هتل و پرواز جهت رزرو تور مورد استفاده قرار میگیرد.
            'SearchByRouteAndDate' => '/api/Partners/Tour/Availability/V2/SearchPackage', // این متد جهت دریافت اطلاعات هتل و پرواز جهت رزرو تور مورد استفاده قرار میگیرد.

            // Boking
            'Lock' => '/api/Partners/Tour/Booking/V1/Lock', // این متد جهت قفل کردن ظرفیت و نرخ هتل و پرواز (تور) مورد استفاده قرار میگیرد.
            'Book' => '/api/Partners/Tour/Booking/V1/Book', //  این متد جهت رزرو تور مورد استفاده قرار میگیرد.
        ];

        if ($request == 'Transaction') return SepehrApi::resultAPI2ResultSys('flight', $request, $Method[$request], null, false, $branch);

        else if ($request == 'CurrentBalance' || $request == 'SearchByRouteAndDate') {
            foreach ($suppliers as $supplier) {
                $tempApi = DB::table('application_interface')->where('object_type', 'colleague')->where('object', $suppliers[0])->where('status', 1)->where('type', 'api')->where('service', 'sepehr')->first();
                $param = ['Username' => $tempApi->username, 'Password' => md5($tempApi->password)];

                foreach ($data as $key => $value) $param[$key] = $value;
                $get_api = Http::post($tempApi->url . $Method[$request], $param)->throw()->json();
            }
            return $array_get_api = [
                'Supplier' => $supplier,
                'Nira' => (isset(json_decode($tempApi->data)->nira) ? json_decode($tempApi->data)->nira : false),
                'CurrencyCode' => $get_api['CurrencyCode'],
                'Packages' => $get_api
            ];
            return SepehrApi::resultAPI2ResultSys(
                'flight',
                $request,
                $array_get_api,
                null,
                false,
                $branch
            );
        } else if ($request == 'GetContent') {
            $tempApi = DB::table('application_interface')->where('object_type', 'colleague')->where('object', $suppliers[0])->where('status', 1)->where('type', 'api')->where('service', 'sepehr')->first();
            $param = ['Username' => $tempApi->username, 'Password' => md5($tempApi->password)];
            foreach ($data as $key => $value) $param[$key] = $value;

            try {
                $result = Http::post($tempApi->url . $Method[$request], $param)->throw(function ($response, $e) {
                    return $e;
                })->json();
                return $result;
            } catch (Throwable $e) {
            }
        };
    }

    function sendRequestAccommodation($request, array $data = [], $method = 'POST', $suppliers = false)
    {
        if ($suppliers) {
            $suppliers = DB::table('application_interface')->where('status', 1)->where('type', 'api')->where('service', 'sepehr_hotel')->whereIn('id', $suppliers)->get();

            $Method = [
                // Get Transaction
                'transaction' => env('SEPEHR_TRANSACTION'),

                // Content
                'get_content' => '/api/Hotel/GetContent/V3', // دریافت لیست هتل هایی که سیستم سپهر از فروش آنها پشتیبانی می کند

                // Availability
                'search_by_city_and_date' => '/api/Partners/Hotel/Availability/V3/SearchByCityAndDate', // دریافت هتل های قابل رزرو در یک شهر
                'get_by_date_range' => '/api/Partners/Hotel/Availability/V3/GetByDateRange', // برای زمانی است که شما می خواهید اطلاعات را در سیستم خود Cache کنید و سپس درخواست های availability را از Cache خود پاسخ دهید

                // Booking
                'lock' => '/api/Partners/Hotel/Booking/V4/Lock', // جهت قفل کردن هتل
                'book' => '/api/Partners/Hotel/Booking/V4/Book', // رزرو هتل

                // GetStatus
                'get_status' => '/api/Partners/Hotel/RetrieveBooking/V1/GetStatus' // با استفاده از این متد می توانید وضعیت درخواست رزرو و اینکه آیا رزرو صادر شده است یا خیر را بررسی نمایید.
            ];
            $param = [];
            foreach ($data as $key => $value) $param[$key] = $value;
            if ($request == 'get_content') {
                $apiResult = [];
                foreach ($suppliers as $supplier) {
                    $param = array_merge($param, ['Username' => $supplier->username, 'Password' => md5($supplier->password)]);
                    $result = Http::get($supplier->url . $Method[$request], $param)->throw()->json();
                    $apiResult[] = [
                        'status' => true,
                        'Time' => time(),
                        'supplier' => $supplier->id,
                        'Information' => $result,
                    ];
                }
                return SepehrApi::accommodationResultAPI2ResultSys('hotel', $request, $apiResult);
            } elseif ($request == 'search_by_city_and_date') {
                $apiResult = [];
                foreach ($suppliers as $supplier) {
                    $param = array_merge($param, ['Username' => $supplier->username, 'Password' => md5($supplier->password)]);
                    $result = Http::post($supplier->url . $Method[$request], $param)->throw()->json();
                    $apiResult[] = [
                        'status' => true,
                        'Time' => time(),
                        'supplier' => $supplier->id,
                        'CurrencyCode' => $result['CurrencyCode'],
                        'Information' => $result['HotelList']
                    ];
                }
                return SepehrApi::accommodationResultAPI2ResultSys('hotel', $request, $apiResult);
            } elseif ($request == 'transaction') {
                return SepehrApi::accommodationResultAPI2ResultSys('hotel', $request, $Method[$request]);
            } else {
                $param = array_merge($param, ['Username' => $suppliers[0]->username, 'Password' => md5($suppliers[0]->password)]);
                return SepehrApi::accommodationResultAPI2ResultSys('hotel', $request, Http::post($suppliers[0]->url . $Method[$request], $param)->json());
            }
        } else {
            return [
                'Data' => [
                    'Status' => false,
                    'Code' => '2004',
                    'Message' => 'هیچ تامین کننده ای برای ارسال درخواست انتخاب نشده است.'
                ]
            ];
        }
    }

    public function getErrorMsg($error)
    {
        $Error = [
            'Error1001-FlightNotFound' => 'پروازی با اطلاعات درخواستی پیدا نشد.',
            'Error1002-NoEnoughSeatAvailable' => 'تعداد صندلی درخواستی در پرواز موجود نمی باشد.',
            'Error1003-NoEnoughSeatAvailable' => 'تعداد صندلی درخواستی در پرواز موجود نمی باشد.',
            'Error1004-NoEnoughCredit' => 'باقی مانده اعتبار حساب برای انجام این رزرو کافی نیست.',
            'Error1005-CreditDueDateReached' => 'مهلت پرداخت بدهی به اتمام رسیده است و انجام رزرو امکان پذیر نمی باشد',
            'Error1006-FareNotFound' => 'کلاس پروازی با اسم fare درخواستی پیدا نشد.',
            'Error1007-DuplicateClientPnr' => 'مقداری که به عنوان YourLocalInventoryPnr ارسال شده است تکراری بوده و قبلا در سیستم ثبت شده است.',
            'Error1008-PenaltyIsNotDefinedException' => 'جریمه توسط تامین کننده تعریف نشده است و رزرو را فقط به صورت تلفنی می توان کنسل نمود',
            'Error1009-PenaltyMismatchException' => 'مبلغ جریمه ارسال شده با جریمه تعریف شده در سیستم مطابقت ندارد.',
            'Error1010-LockReleased' => 'قفل رزرو آزاد شده است و رزرو قابل انجام شدن نیست. این اتفاق زمانی به وقوع می پیوندد که یا زمان بین قفل کردن تا فراخوانی متد Book، بیش از اندازه طولانی شده باشد و یا اینکه قفل رزرو توسط مدیر سیستم در سایت تامین کننده آزاد شده باشد.',
            'Error1011-FlightTimeMismatch' => 'ساعت پرواز درخواستی شما با ساعت پروازی سیستم مطابقت ندارد',
            'Error1012-ForbiddenNationality' => 'زمانی که پذیرش اتباع یک کشور خاص روی مسیری ممنوع باشد، این خطا برگشت داده خواهد شد. به عنوان مثال پذیرش اتباع محترم افغانستان و پاکستان در مسیر استانبول توسط هواپیمایی معراج ممنوع می باشد.',
            'Error1013-FlightLockCountLimit' => 'زمانی که روی یک پرواز اقدام به قفل کردن بیش از 9 عدد صندلی نمایید، این خطا برگشت داده خواهد شد.',
            'Exception' => 'خطای نامشخص. جهت دریافت اطلاعات بیشتر باید به ErrorMessage داخل json برگشتی مراجعه نمود.'
        ];
        return $Error[$error];
    }

    static function resultAPI2ResultSys($goal, $method, $data, $subData = null, $details = false, $branch = false)
    {
        if ($method == 'SearchByRouteAndDate') {
            foreach ($data as $dataItem) {
                if ($dataItem['status']) {
                    foreach ($dataItem['CharterFlights'] as $item) {
                        $Origin = Airport::select('country')->where('iata', $item['Origin']['Code'])->first();
                        $Destination = Airport::select('country')->where('iata', $item['Destination']['Code'])->first();
                        $Classes = [];
                        foreach ($item['Classes'] as $class) {
                            if ($branch) {
                                if (!strpos($branch, 'b2c-')) {
                                    $tempBranch = DB::table('offices')->select('type', 'base_online')->where('id', str_replace('b2c-', '', $branch))->first();
                                    if (is_null($tempBranch->base_online))
                                        $checkFinancial = true;
                                    else
                                        $checkFinancial = false;
                                } else {
                                    $checkFinancial = false;
                                }
                            }
                            if ($branch && $checkFinancial) {
                                $markupADL = 0;
                                $markupCHI = 0;
                                $markupINF = 0;
                                $tempFinancial = [
                                    'PriceAdditions' => [
                                        'Citizens' => 0,
                                    ],
                                    'CommissionPaid' => [
                                        'Percentage' => false,
                                        'Transaction' => env('SEPEHR_TRANSACTION'),
                                        'MembershipRight' => false,
                                    ],
                                    'Adult' => [
                                        'BaseFare' => (int)$class['AdultFare']['BaseFare'],
                                        'Tax' => (int)$class['AdultFare']['Tax'],
                                        'Markup' => $markupADL,
                                        'TotalFare' => (int)$class['AdultFare']['TotalFare'],
                                        'Payable' => ((int)$class['AdultFare']['TotalFare'] + (($markupADL / 100) * (int)$class['AdultFare']['TotalFare'])),
                                        'Commission' => [
                                            'Percentage' => (((int)$class['AdultFare']['Commision'] / (int)$class['AdultFare']['TotalFare']) * 100),
                                            'Final' => (int)$class['AdultFare']['Commision'],
                                            'Price' => (int)$class['AdultFare']['Commision']
                                        ]
                                    ],
                                    'Child' => [
                                        'BaseFare' => (int)$class['ChildFare']['BaseFare'],
                                        'Tax' => (int)$class['ChildFare']['Tax'],
                                        'Markup' => $markupCHI,
                                        'TotalFare' => (int)$class['ChildFare']['TotalFare'],
                                        'Payable' => ((int)$class['ChildFare']['TotalFare'] + (($markupADL / 100) * (int)$class['ChildFare']['TotalFare'])),
                                        'Commission' => [
                                            'Percentage' => (((int)$class['ChildFare']['Commision'] / (int)$class['ChildFare']['TotalFare']) * 100),
                                            'Final' => (int)$class['ChildFare']['Commision'],
                                            'Price' => (int)$class['ChildFare']['Commision']
                                        ]
                                    ],
                                    'Infant' => [
                                        'BaseFare' => (int)$class['InfantFare']['BaseFare'],
                                        'Tax' => (int)$class['InfantFare']['Tax'],
                                        'Markup' => $markupINF,
                                        'TotalFare' => (int)$class['InfantFare']['TotalFare'],
                                        'Payable' => ((int)$class['InfantFare']['TotalFare'] + (($markupADL / 100) * (int)$class['InfantFare']['TotalFare'])),
                                        'Commission' => [
                                            'Percentage' => (((int)$class['InfantFare']['Commision'] / (int)$class['InfantFare']['TotalFare']) * 100),
                                            'Final' => (int)$class['InfantFare']['Commision'],
                                            'Price' => (int)$class['InfantFare']['Commision']
                                        ]
                                    ]
                                ];
                            } else {
                                $tempFinancial = [
                                    'PriceAdditions' => [
                                        'Citizens' => 0,
                                    ],
                                    'CommissionPaid' => [
                                        'Percentage' => false,
                                        'Transaction' => env('SEPEHR_TRANSACTION'),
                                        'MembershipRight' => false,
                                    ],
                                    'Adult' => [
                                        'BaseFare' => (int)$class['AdultFare']['BaseFare'],
                                        'Tax' => (int)$class['AdultFare']['Tax'],
                                        'Markup' => 0,
                                        'TotalFare' => (int)$class['AdultFare']['TotalFare'],
                                        'Payable' => (int)$class['AdultFare']['Payable'],
                                        'Commission' => [
                                            'Percentage' => (((int)$class['AdultFare']['Commision'] / (int)$class['AdultFare']['TotalFare']) * 100),
                                            'Final' => (int)$class['AdultFare']['Commision'],
                                            'Price' => (int)$class['AdultFare']['Commision']
                                        ]
                                    ],
                                    'Child' => [
                                        'BaseFare' => (int)$class['ChildFare']['BaseFare'],
                                        'Tax' => (int)$class['ChildFare']['Tax'],
                                        'Markup' => 0,
                                        'TotalFare' => (int)$class['ChildFare']['TotalFare'],
                                        'Payable' => (int)$class['ChildFare']['Payable'],
                                        'Commission' => [
                                            'Percentage' => (((int)$class['ChildFare']['Commision'] / (int)$class['ChildFare']['TotalFare']) * 100),
                                            'Final' => (int)$class['ChildFare']['Commision'],
                                            'Price' => (int)$class['ChildFare']['Commision']
                                        ]
                                    ],
                                    'Infant' => [
                                        'BaseFare' => (int)$class['InfantFare']['BaseFare'],
                                        'Tax' => (int)$class['InfantFare']['Tax'],
                                        'Markup' => 0,
                                        'TotalFare' => (int)$class['InfantFare']['TotalFare'],
                                        'Payable' => (int)$class['InfantFare']['Payable'],
                                        'Commission' => [
                                            'Percentage' => (((int)$class['InfantFare']['Commision'] / (int)$class['InfantFare']['TotalFare']) * 100),
                                            'Final' => (int)$class['InfantFare']['Commision'],
                                            'Price' => (int)$class['InfantFare']['Commision']
                                        ]
                                    ]
                                ];
                            }
                            $getSystemSupplier = SepehrApi::getSupplier($item['Airline'], $item['SystemSupplier']);
                            $Classes[] = [
                                'FlightStatus' => true,
                                'Reservable' => true,
                                'Status' => 'reservable',
                                'CancelationPolicy' => true,
                                'BookingPolicy' => (!is_null($class['BookingPolicy'])) ? true : false,
                                'Supplier' => (isset($item['Nira']) && !$dataItem['isHub'] ? SepehrApi::getDetails('supplier', $getSystemSupplier['id'], $details, $branch, false, $dataItem['isHub']) : SepehrApi::getDetails('supplier', $item['SystemSupplier'], $details, $branch, $dataItem['isHub'])),
                                'SystemSupplier' => SepehrApi::getDetails('system_supplier', $item['SystemSupplier'], $details, $branch),
                                'FlightId' => $class['BookingCode'],
                                'FareName' => $class['FareName'],
                                'CabinType' => SepehrApi::ChangeCabinType($class['CabinType'], $details),
                                'AvailableSeat' => $class['AvailableSeat'],
                                'Rules' => false,
                                'Financial' => $tempFinancial,
                                "BaseData" => [
                                    "Supplier" => [
                                        'Supplier' => (isset($item['Nira']) ? SepehrApi::getDetails('supplier', $getSystemSupplier['id'], $details) : SepehrApi::getDetails('supplier', $item['SystemSupplier'], $details)),
                                        'SystemSupplier' => SepehrApi::getDetails('system_supplier', $item['SystemSupplier'], $details),
                                    ],
                                    'Financial' => [
                                        'PriceAdditions' => [
                                            'Citizens' => 0,
                                        ],
                                        'CommissionPaid' => [
                                            'Percentage' => false,
                                            'Transaction' => env('SEPEHR_TRANSACTION'),
                                            'MembershipRight' => false,
                                        ],
                                        'Adult' => [
                                            'BaseFare' => (int)$class['AdultFare']['BaseFare'],
                                            'Tax' => (int)$class['AdultFare']['Tax'],
                                            'Markup' => 0,
                                            'TotalFare' => (int)$class['AdultFare']['TotalFare'],
                                            'Payable' => (int)$class['AdultFare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => (((int)$class['AdultFare']['Commision'] / (int)$class['AdultFare']['TotalFare']) * 100),
                                                'Final' => (int)$class['AdultFare']['Commision'],
                                                'Price' => (int)$class['AdultFare']['Commision']
                                            ]
                                        ],
                                        'Child' => [
                                            'BaseFare' => (int)$class['ChildFare']['BaseFare'],
                                            'Tax' => (int)$class['ChildFare']['Tax'],
                                            'Markup' => 0,
                                            'TotalFare' => (int)$class['ChildFare']['TotalFare'],
                                            'Payable' => (int)$class['ChildFare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => (((int)$class['ChildFare']['Commision'] / (int)$class['ChildFare']['TotalFare']) * 100),
                                                'Final' => (int)$class['ChildFare']['Commision'],
                                                'Price' => (int)$class['ChildFare']['Commision']
                                            ]
                                        ],
                                        'Infant' => [
                                            'BaseFare' => (int)$class['InfantFare']['BaseFare'],
                                            'Tax' => (int)$class['InfantFare']['Tax'],
                                            'Markup' => 0,
                                            'TotalFare' => (int)$class['InfantFare']['TotalFare'],
                                            'Payable' => (int)$class['InfantFare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => (((int)$class['InfantFare']['Commision'] / (int)$class['InfantFare']['TotalFare']) * 100),
                                                'Final' => (int)$class['InfantFare']['Commision'],
                                                'Price' => (int)$class['InfantFare']['Commision']
                                            ]
                                        ]
                                    ],
                                ],
                                'Baggage' => [
                                    'Adult' => [
                                        'Trunk' => [
                                            'Number' => $class['AdultFreeBaggage']['CheckedBaggageQuantity'],
                                            'TotalWeight' => $class['AdultFreeBaggage']['CheckedBaggageTotalWeight'],
                                        ],
                                        'Hand' => [
                                            'Number' => $class['AdultFreeBaggage']['HandBaggageQuantity'],
                                            'TotalWeight' => $class['AdultFreeBaggage']['HandBaggageTotalWeight'],
                                        ]
                                    ],
                                    'Child' => [
                                        'Trunk' => [
                                            'Number' => $class['ChildFreeBaggage']['CheckedBaggageQuantity'],
                                            'TotalWeight' => $class['ChildFreeBaggage']['CheckedBaggageTotalWeight'],
                                        ],
                                        'Hand' => [
                                            'Number' => $class['ChildFreeBaggage']['HandBaggageQuantity'],
                                            'TotalWeight' => $class['ChildFreeBaggage']['HandBaggageTotalWeight'],
                                        ]
                                    ],
                                    'Infant' => [
                                        'Trunk' => [
                                            'Number' => $class['InfantFreeBaggage']['CheckedBaggageQuantity'],
                                            'TotalWeight' => $class['InfantFreeBaggage']['CheckedBaggageTotalWeight'],
                                        ],
                                        'Hand' => [
                                            'Number' => $class['InfantFreeBaggage']['HandBaggageQuantity'],
                                            'TotalWeight' => $class['InfantFreeBaggage']['HandBaggageTotalWeight'],
                                        ]
                                    ]
                                ],
                                'Remarks' => isset($class['BookingPolicy']) && !is_null($class['BookingPolicy']) ? [
                                    'Inbound' => [
                                        'AirlineSupplier' => false,
                                        'TourRequirement' => $class['BookingPolicy']['RestrictedForTour'],
                                        'OneWayRequirement' => $class['BookingPolicy']['ReturningFlightMustNotEqualToAnyFlight'],
                                        'RoundtripRequirement' => $class['BookingPolicy']['RestrictedForTour'],
                                        'PhoneRequirement' => false,
                                        'Description' => $class['CancelationPolicy'],
                                        'Special' => false,
                                        'Warranty' => false,
                                    ],
                                    'Outbound' => ($class['BookingPolicy']['ReturningFlightMustEqualToAnyFlight']) ? [
                                        'AllowedReturnFlights' => (isset($class['BookingPolicy']['ReturningFlightMustEqualList'])) ? $class['BookingPolicy']['ReturningFlightMustEqualList'] : false,
                                        'UnauthorizedReturnFlights' => (isset($class['BookingPolicy']['ReturningFlightMustNotEqualList'])) ? $class['BookingPolicy']['ReturningFlightMustNotEqualList'] : false,
                                        'ReturnOnlyFromTheAirlineOfOrigin' => ($class['BookingPolicy']['RestrictedReturningBySameAirline']) ? true : false,
                                        'ReturnFlightProvider' => (isset($item['SupplierId'])) ? $item['SupplierId'] : false,
                                        'DistanceToReturnFlight' => (isset($class['BookingPolicy']['FareMinStay']) || isset($class['BookingPolicy']['FareMaxStay'])) ? [
                                            'Min' => (isset($class['BookingPolicy']['FareMinStay'])) ? $class['BookingPolicy']['FareMinStay']['MinimumStayDay'] : 0,
                                            'Max' => (isset($class['BookingPolicy']['FareMaxStay'])) ? $class['BookingPolicy']['FareMaxStay']['MaximumStayDay'] : 0,
                                        ] : false,
                                    ] : false
                                ] : false,

                            ];
                        }

                        if (isset($item['Step1'])) { // در صورت وجود اولین توقف
                            $Step1 = [
                                'StopoverAirport' => $item['Step1']['AirportIataCode'],
                                'TimeFromStartToStop' => $item['Step1']['StopDurationInMinute'],
                                'StopTime' => $item['Step1']['StopDurationInMinute'],
                                'ArrivalDateTime' => $item['Step1']['ArrivalDateTime'],
                                'DepartureDateTime' => $item['Step1']['DepartureDateTime']
                            ];
                        }

                        if (isset($item['Step2'])) { // در صورت وجود دومین توقف
                            $Step2 = [
                                'StopoverAirport' => $item['Step2']['AirportIataCode'],
                                'TimeFromStartToStop' => $item['Step2']['StopDurationInMinute'],
                                'StopTime' => $item['Step2']['StopDurationInMinute'],
                                'ArrivalDateTime' => $item['Step2']['ArrivalDateTime'],
                                'DepartureDateTime' => $item['Step2']['DepartureDateTime']
                            ];
                        }

                        if (!is_null($class['BookingPolicy'])) { // مرتب سازی قوانین پروازی
                            $ReturningFlightMustNotEqualToAnyFlight = ($class['BookingPolicy']['ReturningFlightMustNotEqualToAnyFlight']) ? true : false;
                            $ReturningFlightRestrictedForTour = ($class['BookingPolicy']['RestrictedForTour']) ? true : false;
                            $ReturningFlightReturningFlightMustEqualToAnyFlight = ($class['BookingPolicy']['ReturningFlightMustEqualToAnyFlight']) ? true : false;
                        } else {
                            $ReturningFlightMustNotEqualToAnyFlight = false;
                            $ReturningFlightRestrictedForTour = false;
                            $ReturningFlightReturningFlightMustEqualToAnyFlight = false;
                        }

                        $Flights[] = [
                            'Service' => 'sepehr',
                            'ServiceId' => $dataItem['serviceId'],
                            'ServiceBranch' => $dataItem['serviceBranch'],
                            'DisplayableService' => $dataItem['isHub'] ? 'airplusHub' : 'sepehr',
                            'Verified' => ($item['SystemSupplier'] == 34 ? true : false),
                            'FlightType' => 'Charter',
                            'FlightRoute' => ($Origin['country'] != env('COUNTRY_CODE') || $Destination['country'] != env('COUNTRY_CODE')) ? 'International' : 'Internal',
                            'FlightNumber' => $item['FlightNumber'],
                            'Origin' => [
                                'Iata' => SepehrApi::getDetails('airport', $item['Origin']['Code'], $details),
                                'Terminal' => (!is_null($item['Origin']['Terminal'])) ? true : false
                            ],
                            'Destination' => [
                                'Iata' => SepehrApi::getDetails('airport', $item['Destination']['Code'], $details),
                                'Terminal' => (!is_null($item['Destination']['Terminal'])) ? true : false
                            ],
                            'DepartureDateTime' => $item['DepartureDateTime'] . ':00',
                            'ArrivalDateTime' => $item['ArrivalDateTime'] . ':00',
                            'Duration' => $item['Duration'],
                            'Aircraft' => SepehrApi::getDetails('aircraft', $item['Aircraft'], $details),
                            'Airline' => SepehrApi::getDetails('airline', $item['Airline'], $details),
                            'Remarks' => [
                                'AirlineSupplier' => false,
                                'TourRequirement' => $ReturningFlightRestrictedForTour,
                                'OneWayRequirement' => $ReturningFlightMustNotEqualToAnyFlight,
                                'RoundtripRequirement' => $ReturningFlightReturningFlightMustEqualToAnyFlight,
                                'PhoneRequirement' => false,
                                'Description' => $item['Remarks'],
                                'Special' => false,
                                'Warranty' => false,
                            ],
                            'ReturningFlight' => ($ReturningFlightReturningFlightMustEqualToAnyFlight) ? [
                                'AllowedReturnFlights' => (isset($class['BookingPolicy']['ReturningFlightMustEqualList'])) ? $class['BookingPolicy']['ReturningFlightMustEqualList'] : false,
                                'UnauthorizedReturnFlights' => (isset($class['BookingPolicy']['ReturningFlightMustNotEqualList'])) ? $class['BookingPolicy']['ReturningFlightMustNotEqualList'] : false,
                                'ReturnOnlyFromTheAirlineOfOrigin' => ($class['BookingPolicy']['RestrictedReturningBySameAirline']) ? true : false,
                                'ReturnFlightProvider' => (isset($item['SupplierId'])) ? $item['SupplierId'] : false,
                                'DistanceToReturnFlight' => (isset($class['BookingPolicy']['FareMinStay']) || isset($class['BookingPolicy']['FareMaxStay'])) ? [
                                    'Min' => (isset($class['BookingPolicy']['FareMinStay'])) ? $class['BookingPolicy']['FareMinStay']['MinimumStayDay'] : 0,
                                    'Max' => (isset($class['BookingPolicy']['FareMaxStay'])) ? $class['BookingPolicy']['FareMaxStay']['MaximumStayDay'] : 0,
                                ] : false,
                            ] : false,
                            'Steps' => (isset($item['Step1']) or isset($item['Step2'])) ? [
                                (isset($Step1)) ? $Step1 : null,
                                (isset($Step2)) ? $Step2 : null
                            ] : false,
                            'Classes' => $Classes
                        ];
                    }

                    foreach ($dataItem['WebserviceFlights'] as $item) {
                        $Origin = Airport::select('country')->where('iata', $item['Origin']['Code'])->first();
                        $Destination = Airport::select('country')->where('iata', $item['Destination']['Code'])->first();

                        $Classes = [];
                        foreach ($item['Classes'] as $class) {

                            // در صورتی که پرواز وب سرویسی دارای دو آیتم سیستمی یا غیر سیستمی باشند این متغییر مقدار دهی میشود
                            if (isset($item['IsAirlineScheduleFlight']))
                                $Reservable = ($item['IsAirlineScheduleFlight']) ? false : true;
                            elseif (isset($item['IsParvazSystemiAirline']))
                                $Reservable = ($item['IsParvazSystemiAirline']) ? false : true;
                            else $Reservable = false;

                            // در صورتی که پرواز الزاما دو طرفه باشد قیمت از فرودگاه مبدا دریافت میگردد
                            if (isset($class['BookingPolicy'])) {
                                // $ReturningFlightMustEqualToAnyFlight = ($class['BookingPolicy']['ReturningFlightMustEqualToAnyFlight']) ? $class['RoundtripFare_FromOrigin'] : $class;
                                $ReturningFlightMustEqualToAnyFlighAdult = $class['AdultFare'];
                                $ReturningFlightMustEqualToAnyFlightChild = $class['ChildFare'];
                                $ReturningFlightMustEqualToAnyFlightInfant = $class['InfantFare'];
                            } else {
                                $ReturningFlightMustEqualToAnyFlighAdult = $class['AdultFare'];
                                $ReturningFlightMustEqualToAnyFlightChild = $class['ChildFare'];
                                $ReturningFlightMustEqualToAnyFlightInfant = $class['InfantFare'];
                            }

                            if (isset($class['RoundtripFare_FromOrigin']) && isset($class['RoundtripFare_FromDestination'])) {
                                $RoundtripFare = [ // مالی در صورت پرواز دو طرفه
                                    'FromOrigin' => [ // قیمت در فرودگاه مبدا
                                        'PriceAdditions' => [
                                            'Citizens' => 0,
                                        ],
                                        'CommissionPaid' => [
                                            'Percentage' => false,
                                            'Transaction' => env('SEPEHR_TRANSACTION'),
                                            'MembershipRight' => false,
                                        ],
                                        'Adult' => [
                                            'BaseFare' => (int)$class['RoundtripFare_FromOrigin']['Adult_Fare']['BaseFare'],
                                            'Tax' => (int)$class['RoundtripFare_FromOrigin']['Adult_Fare']['Tax'],
                                            'Markup' => (int)$class['RoundtripFare_FromOrigin']['Adult_Fare']['Markup'],
                                            'TotalFare' => (int)$class['RoundtripFare_FromOrigin']['Adult_Fare']['TotalFare'],
                                            'Payable' => (int)$class['RoundtripFare_FromOrigin']['Adult_Fare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => (((int)$class['RoundtripFare_FromOrigin']['Adult_Fare']['Commision'] / (int)$class['RoundtripFare_FromOrigin']['Adult_Fare']['TotalFare']) * 100),
                                                'Final' => (int)$class['RoundtripFare_FromOrigin']['Adult_Fare']['Commision'],
                                                'Price' => (int)$class['RoundtripFare_FromOrigin']['Adult_Fare']['Commision']
                                            ]
                                        ],
                                        'Child' => [
                                            'BaseFare' => (int)$class['RoundtripFare_FromOrigin']['Child_Fare']['BaseFare'],
                                            'Tax' => (int)$class['RoundtripFare_FromOrigin']['Child_Fare']['Tax'],
                                            'Markup' => (int)$class['RoundtripFare_FromOrigin']['Child_Fare']['Markup'],
                                            'TotalFare' => (int)$class['RoundtripFare_FromOrigin']['Child_Fare']['TotalFare'],
                                            'Payable' => (int)$class['RoundtripFare_FromOrigin']['Child_Fare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => (((int)$class['RoundtripFare_FromOrigin']['Child_Fare']['Commision'] / (int)$class['RoundtripFare_FromOrigin']['Child_Fare']['TotalFare']) * 100),
                                                'Final' => (int)$class['RoundtripFare_FromOrigin']['Child_Fare']['Commision'],
                                                'Price' => (int)$class['RoundtripFare_FromOrigin']['Child_Fare']['Commision']
                                            ]
                                        ],
                                        'Infant' => [
                                            'BaseFare' => (int)$class['RoundtripFare_FromOrigin']['Infant_Fare']['BaseFare'],
                                            'Tax' => (int)$class['RoundtripFare_FromOrigin']['Infant_Fare']['Tax'],
                                            'Markup' => (int)$class['RoundtripFare_FromOrigin']['Infant_Fare']['Markup'],
                                            'TotalFare' => (int)$class['RoundtripFare_FromOrigin']['Infant_Fare']['TotalFare'],
                                            'Payable' => (int)$class['RoundtripFare_FromOrigin']['Infant_Fare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => (((int)$class['RoundtripFare_FromOrigin']['Infant_Fare']['Commision'] / (int)$class['RoundtripFare_FromOrigin']['Infant_Fare']['TotalFare']) * 100),
                                                'Final' => (int)$class['RoundtripFare_FromOrigin']['Infant_Fare']['Commision'],
                                                'Price' => (int)$class['RoundtripFare_FromOrigin']['Infant_Fare']['Commision']
                                            ]
                                        ]
                                    ],
                                    'FromDestination' => [ // قیمت در فرودگاه مقصد
                                        'PriceAdditions' => [
                                            'Citizens' => 0,
                                        ],
                                        'CommissionPaid' => [
                                            'Percentage' => false,
                                            'Transaction' => env('SEPEHR_TRANSACTION'),
                                            'MembershipRight' => false,
                                        ],
                                        'Adult' => [
                                            'BaseFare' => (int)$class['RoundtripFare_FromDestination']['Adult_Fare']['BaseFare'],
                                            'Tax' => (int)$class['RoundtripFare_FromDestination']['Adult_Fare']['Tax'],
                                            'Markup' => (int)$class['RoundtripFare_FromDestination']['Adult_Fare']['Markup'],
                                            'TotalFare' => (int)$class['RoundtripFare_FromDestination']['Adult_Fare']['TotalFare'],
                                            'Payable' => (int)$class['RoundtripFare_FromDestination']['Adult_Fare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => (((int)$class['RoundtripFare_FromDestination']['Adult_Fare']['Commision'] / (int)$class['RoundtripFare_FromDestination']['Adult_Fare']['TotalFare']) * 100),
                                                'Final' => (int)$class['RoundtripFare_FromDestination']['Adult_Fare']['Commision'],
                                                'Price' => (int)$class['RoundtripFare_FromDestination']['Adult_Fare']['Commision']
                                            ]
                                        ],
                                        'Child' => [
                                            'BaseFare' => (int)$class['RoundtripFare_FromDestination']['Child_Fare']['BaseFare'],
                                            'Tax' => (int)$class['RoundtripFare_FromDestination']['Child_Fare']['Tax'],
                                            'Markup' => (int)$class['RoundtripFare_FromDestination']['Child_Fare']['Markup'],
                                            'TotalFare' => (int)$class['RoundtripFare_FromDestination']['Child_Fare']['TotalFare'],
                                            'Payable' => (int)$class['RoundtripFare_FromDestination']['Child_Fare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => (((int)$class['RoundtripFare_FromDestination']['Child_Fare']['Commision'] / (int)$class['RoundtripFare_FromDestination']['Child_Fare']['TotalFare']) * 100),
                                                'Final' => (int)$class['RoundtripFare_FromDestination']['Child_Fare']['Commision'],
                                                'Price' => (int)$class['RoundtripFare_FromDestination']['Child_Fare']['Commision']
                                            ]
                                        ],
                                        'Infant' => [
                                            'BaseFare' => (int)$class['RoundtripFare_FromDestination']['Infant_Fare']['BaseFare'],
                                            'Tax' => (int)$class['RoundtripFare_FromDestination']['Infant_Fare']['Tax'],
                                            'Markup' => (int)$class['RoundtripFare_FromDestination']['Infant_Fare']['Markup'],
                                            'TotalFare' => (int)$class['RoundtripFare_FromDestination']['Infant_Fare']['TotalFare'],
                                            'Payable' => (int)$class['RoundtripFare_FromDestination']['Infant_Fare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => (((int)$class['RoundtripFare_FromDestination']['Infant_Fare']['Commision'] / (int)$class['RoundtripFare_FromDestination']['Infant_Fare']['TotalFare']) * 100),
                                                'Final' => (int)$class['RoundtripFare_FromDestination']['Infant_Fare']['Commision'],
                                                'Price' => (int)$class['RoundtripFare_FromDestination']['Infant_Fare']['Commision']
                                            ]
                                        ]
                                    ]
                                ];
                            } else $RoundtripFare = false;

                            if ($branch) {
                                if (!strpos($branch, 'b2c-')) {
                                    $tempBranch = DB::table('offices')->select('type', 'base_online')->where('id', str_replace('b2c-', '', $branch))->first();
                                    if ($tempBranch && is_null($tempBranch->base_online))
                                        $checkFinancial = true;
                                    else
                                        $checkFinancial = false;
                                } else {
                                    $checkFinancial = false;
                                }
                            }
                            if ($branch && $checkFinancial) {
                                $markupADL = 0;
                                $markupCHI = 0;
                                $markupINF = 0;
                                $tempFinancial = [ // قیمت ها و موارد مالی بصورت پیش فرض در پرواز یک طرفه تعیین میگردد
                                    'PriceAdditions' => [
                                        'Citizens' => 0,
                                    ],
                                    'CommissionPaid' => [
                                        'Percentage' => false,
                                        'Transaction' => env('SEPEHR_TRANSACTION'),
                                        'MembershipRight' => false,
                                    ],
                                    'Adult' => [
                                        'BaseFare' => (int)$ReturningFlightMustEqualToAnyFlighAdult['BaseFare'],
                                        'Tax' => (int)$ReturningFlightMustEqualToAnyFlighAdult['Tax'],
                                        'Markup' => $markupADL,
                                        'TotalFare' => (int)$ReturningFlightMustEqualToAnyFlighAdult['TotalFare'],
                                        'Payable' => ((int)$ReturningFlightMustEqualToAnyFlighAdult['TotalFare'] + (($markupADL / 100) * (int)$ReturningFlightMustEqualToAnyFlighAdult['TotalFare'])),
                                        'Commission' => [
                                            'Percentage' => (((int)$ReturningFlightMustEqualToAnyFlighAdult['Commision'] / (int)$ReturningFlightMustEqualToAnyFlighAdult['TotalFare']) * 100),
                                            'Final' => (int)$ReturningFlightMustEqualToAnyFlighAdult['Commision'],
                                            'Price' => (int)$ReturningFlightMustEqualToAnyFlighAdult['Commision']
                                        ]
                                    ],
                                    'Child' => [
                                        'BaseFare' => (int)$ReturningFlightMustEqualToAnyFlightChild['BaseFare'],
                                        'Tax' => (int)$ReturningFlightMustEqualToAnyFlightChild['Tax'],
                                        'Markup' => $markupCHI,
                                        'TotalFare' => (int)$ReturningFlightMustEqualToAnyFlightChild['TotalFare'],
                                        'Payable' => ((int)$ReturningFlightMustEqualToAnyFlightChild['TotalFare'] + (($markupCHI / 100) * (int)$ReturningFlightMustEqualToAnyFlightChild['TotalFare'])),
                                        'Commission' => [
                                            'Percentage' => (((int)$ReturningFlightMustEqualToAnyFlightChild['Commision'] / (int)$ReturningFlightMustEqualToAnyFlightChild['TotalFare']) * 100),
                                            'Final' => (int)$ReturningFlightMustEqualToAnyFlightChild['Commision'],
                                            'Price' => (int)$ReturningFlightMustEqualToAnyFlightChild['Commision']
                                        ]
                                    ],
                                    'Infant' => [
                                        'BaseFare' => (int)$ReturningFlightMustEqualToAnyFlightInfant['BaseFare'],
                                        'Tax' => (int)$ReturningFlightMustEqualToAnyFlightInfant['Tax'],
                                        'Markup' => $markupINF,
                                        'TotalFare' => (int)$ReturningFlightMustEqualToAnyFlightInfant['TotalFare'],
                                        'Payable' => ((int)$ReturningFlightMustEqualToAnyFlightInfant['TotalFare'] + (($markupINF / 100) * (int)$ReturningFlightMustEqualToAnyFlightInfant['TotalFare'])),
                                        'Commission' => [
                                            'Percentage' => (((int)$ReturningFlightMustEqualToAnyFlightInfant['Commision'] / (int)$ReturningFlightMustEqualToAnyFlightInfant['TotalFare']) * 100),
                                            'Final' => (int)$ReturningFlightMustEqualToAnyFlightInfant['Commision'],
                                            'Price' => (int)$ReturningFlightMustEqualToAnyFlightInfant['Commision']
                                        ]
                                    ],
                                    'RoundtripFare' => ($RoundtripFare) ? $RoundtripFare : false
                                ];
                            } else {
                                $tempFinancial = [ // قیمت ها و موارد مالی بصورت پیش فرض در پرواز یک طرفه تعیین میگردد
                                    'PriceAdditions' => [
                                        'Citizens' => 0,
                                    ],
                                    'CommissionPaid' => [
                                        'Percentage' => false,
                                        'Transaction' => env('SEPEHR_TRANSACTION'),
                                        'MembershipRight' => false,
                                    ],
                                    'Adult' => [
                                        'BaseFare' => (int)$ReturningFlightMustEqualToAnyFlighAdult['BaseFare'],
                                        'Tax' => (int)$ReturningFlightMustEqualToAnyFlighAdult['Tax'],
                                        'Markup' => (isset($ReturningFlightMustEqualToAnyFlighAdult['Markup'])) ? (int)$ReturningFlightMustEqualToAnyFlighAdult['Markup'] : 0,
                                        'TotalFare' => (int)$ReturningFlightMustEqualToAnyFlighAdult['TotalFare'],
                                        'Payable' => (int)$ReturningFlightMustEqualToAnyFlighAdult['Payable'],
                                        'Commission' => [
                                            'Percentage' => (((int)$ReturningFlightMustEqualToAnyFlighAdult['Commision'] / (int)$ReturningFlightMustEqualToAnyFlighAdult['TotalFare']) * 100),
                                            'Final' => (int)$ReturningFlightMustEqualToAnyFlighAdult['Commision'],
                                            'Price' => (int)$ReturningFlightMustEqualToAnyFlighAdult['Commision']
                                        ]
                                    ],
                                    'Child' => [
                                        'BaseFare' => (int)$ReturningFlightMustEqualToAnyFlightChild['BaseFare'],
                                        'Tax' => (int)$ReturningFlightMustEqualToAnyFlightChild['Tax'],
                                        'Markup' => (isset($ReturningFlightMustEqualToAnyFlightChild['Markup'])) ? (int)$ReturningFlightMustEqualToAnyFlightChild['Markup'] : 0,
                                        'TotalFare' => (int)$ReturningFlightMustEqualToAnyFlightChild['TotalFare'],
                                        'Payable' => (int)$ReturningFlightMustEqualToAnyFlightChild['Payable'],
                                        'Commission' => [
                                            'Percentage' => $ReturningFlightMustEqualToAnyFlightChild['TotalFare'] ? (((int)$ReturningFlightMustEqualToAnyFlightChild['Commision'] / (int)$ReturningFlightMustEqualToAnyFlightChild['TotalFare']) * 100) : 0,
                                            'Final' => (int)$ReturningFlightMustEqualToAnyFlightChild['Commision'],
                                            'Price' => (int)$ReturningFlightMustEqualToAnyFlightChild['Commision']
                                        ]
                                    ],
                                    'Infant' => [
                                        'BaseFare' => (int)$ReturningFlightMustEqualToAnyFlightInfant['BaseFare'],
                                        'Tax' => (int)$ReturningFlightMustEqualToAnyFlightInfant['Tax'],
                                        'Markup' => (isset($ReturningFlightMustEqualToAnyFlightInfant['Markup'])) ? (int)$ReturningFlightMustEqualToAnyFlightInfant['Markup'] : 0,
                                        'TotalFare' => (int)$ReturningFlightMustEqualToAnyFlightInfant['TotalFare'],
                                        'Payable' => (int)$ReturningFlightMustEqualToAnyFlightInfant['Payable'],
                                        'Commission' => [
                                            'Percentage' => (int)$ReturningFlightMustEqualToAnyFlightInfant['TotalFare'] ? (((int)$ReturningFlightMustEqualToAnyFlightInfant['Commision'] / (int)$ReturningFlightMustEqualToAnyFlightInfant['TotalFare']) * 100) : 0,
                                            'Final' => (int)$ReturningFlightMustEqualToAnyFlightInfant['Commision'],
                                            'Price' => (int)$ReturningFlightMustEqualToAnyFlightInfant['Commision']
                                        ]
                                    ],
                                    'RoundtripFare' => ($RoundtripFare) ? $RoundtripFare : false
                                ];
                            }
                            $Classes[] = [
                                'FlightStatus' => true,
                                'Reservable' => $Reservable,
                                'Status' => $Reservable ? 'reservable' : 'full',
                                'CancelationPolicy' => $class['CancelationPolicy'],
                                'BookingPolicy' => (isset($class['BookingPolicy'])) ? $class['BookingPolicy'] : false,
                                'Supplier' => (isset($item['Nira']) && !$dataItem['isHub'] ? SepehrApi::getDetails('supplier', SepehrApi::getSupplier($item['Airline'], $item['SystemSupplier'])['id'], $details, $branch) : SepehrApi::getDetails('supplier', $item['SystemSupplier'], $details, $branch, $dataItem['isHub'])),
                                'SystemSupplier' => SepehrApi::getDetails('system_supplier', $item['SystemSupplier'], $details, $branch),
                                'FlightId' => $class['BookingCode'],
                                'FareName' => $class['FareName'],
                                'CabinType' => SepehrApi::ChangeCabinType($class['CabinType'], $details),
                                'AvailableSeat' => $class['AvailableSeat'],
                                'Rules' => false,
                                'Financial' => $tempFinancial,
                                "BaseData" => [
                                    "Supplier" => [
                                        'Supplier' => (isset($item['Nira']) ? SepehrApi::getDetails('supplier', SepehrApi::getSupplier($item['Airline'], $item['SystemSupplier'])['id'], $details) : SepehrApi::getDetails('supplier', $item['SystemSupplier'], $details)),
                                        'SystemSupplier' => SepehrApi::getDetails('system_supplier', $item['SystemSupplier'], $details),
                                    ],
                                    'Financial' => [
                                        'PriceAdditions' => [
                                            'Citizens' => 0,
                                        ],
                                        'CommissionPaid' => [
                                            'Percentage' => false,
                                            'Transaction' => env('SEPEHR_TRANSACTION'),
                                            'MembershipRight' => false,
                                        ],
                                        'Adult' => [
                                            'BaseFare' => (int)$class['AdultFare']['BaseFare'],
                                            'Tax' => (int)$class['AdultFare']['Tax'],
                                            'Markup' => 0,
                                            'TotalFare' => (int)$class['AdultFare']['TotalFare'],
                                            'Payable' => (int)$class['AdultFare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => $class['AdultFare']['TotalFare'] ? (((int)$class['AdultFare']['Commision'] / (int)$class['AdultFare']['TotalFare']) * 100) : 0,
                                                'Final' => (int)$class['AdultFare']['Commision'],
                                                'Price' => (int)$class['AdultFare']['Commision']
                                            ]
                                        ],
                                        'Child' => [
                                            'BaseFare' => (int)$class['ChildFare']['BaseFare'],
                                            'Tax' => (int)$class['ChildFare']['Tax'],
                                            'Markup' => 0,
                                            'TotalFare' => (int)$class['ChildFare']['TotalFare'],
                                            'Payable' => (int)$class['ChildFare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => $class['ChildFare']['TotalFare'] ? (((int)$class['ChildFare']['Commision'] / (int)$class['ChildFare']['TotalFare']) * 100) : 0,
                                                'Final' => (int)$class['ChildFare']['Commision'],
                                                'Price' => (int)$class['ChildFare']['Commision']
                                            ]
                                        ],
                                        'Infant' => [
                                            'BaseFare' => (int)$class['InfantFare']['BaseFare'],
                                            'Tax' => (int)$class['InfantFare']['Tax'],
                                            'Markup' => 0,
                                            'TotalFare' => (int)$class['InfantFare']['TotalFare'],
                                            'Payable' => (int)$class['InfantFare']['Payable'],
                                            'Commission' => [
                                                'Percentage' => $class['InfantFare']['TotalFare'] ? (((int)$class['InfantFare']['Commision'] / (int)$class['InfantFare']['TotalFare']) * 100) : 0,
                                                'Final' => (int)$class['InfantFare']['Commision'],
                                                'Price' => (int)$class['InfantFare']['Commision']
                                            ]
                                        ]
                                    ],
                                ],
                                'Baggage' => [
                                    'Adult' => [
                                        'Trunk' => [
                                            'Number' => $class['AdultFreeBaggage']['CheckedBaggageQuantity'],
                                            'TotalWeight' => $class['AdultFreeBaggage']['CheckedBaggageTotalWeight'],
                                        ],
                                        'Hand' => [
                                            'Number' => $class['AdultFreeBaggage']['HandBaggageQuantity'],
                                            'TotalWeight' => $class['AdultFreeBaggage']['HandBaggageTotalWeight'],
                                        ]
                                    ],
                                    'Child' => [
                                        'Trunk' => [
                                            'Number' => $class['ChildFreeBaggage']['CheckedBaggageQuantity'],
                                            'TotalWeight' => $class['ChildFreeBaggage']['CheckedBaggageTotalWeight'],
                                        ],
                                        'Hand' => [
                                            'Number' => $class['ChildFreeBaggage']['HandBaggageQuantity'],
                                            'TotalWeight' => $class['ChildFreeBaggage']['HandBaggageTotalWeight'],
                                        ]
                                    ],
                                    'Infant' => [
                                        'Trunk' => [
                                            'Number' => $class['InfantFreeBaggage']['CheckedBaggageQuantity'],
                                            'TotalWeight' => $class['InfantFreeBaggage']['CheckedBaggageTotalWeight'],
                                        ],
                                        'Hand' => [
                                            'Number' => $class['InfantFreeBaggage']['HandBaggageQuantity'],
                                            'TotalWeight' => $class['InfantFreeBaggage']['HandBaggageTotalWeight'],
                                        ]
                                    ]
                                ],
                                'Remarks' => isset($class['BookingPolicy']) && !is_null($class['BookingPolicy']) ? [
                                    'Inbound' => [
                                        'AirlineSupplier' => false,
                                        'TourRequirement' => $class['BookingPolicy']['RestrictedForTour'],
                                        'OneWayRequirement' => $class['BookingPolicy']['ReturningFlightMustNotEqualToAnyFlight'],
                                        'RoundtripRequirement' => $class['BookingPolicy']['RestrictedForTour'],
                                        'PhoneRequirement' => false,
                                        'Description' => $class['CancelationPolicy'],
                                        'Special' => false,
                                        'Warranty' => false,
                                    ],
                                    'Outbound' => ($class['BookingPolicy']['ReturningFlightMustEqualToAnyFlight']) ? [
                                        'AllowedReturnFlights' => (isset($class['BookingPolicy']['ReturningFlightMustEqualList'])) ? $class['BookingPolicy']['ReturningFlightMustEqualList'] : false,
                                        'UnauthorizedReturnFlights' => (isset($class['BookingPolicy']['ReturningFlightMustNotEqualList'])) ? $class['BookingPolicy']['ReturningFlightMustNotEqualList'] : false,
                                        'ReturnOnlyFromTheAirlineOfOrigin' => ($class['BookingPolicy']['RestrictedReturningBySameAirline']) ? true : false,
                                        'ReturnFlightProvider' => (isset($item['SupplierId'])) ? $item['SupplierId'] : false,
                                        'DistanceToReturnFlight' => (isset($class['BookingPolicy']['FareMinStay']) || isset($class['BookingPolicy']['FareMaxStay'])) ? [
                                            'Min' => (isset($class['BookingPolicy']['FareMinStay'])) ? $class['BookingPolicy']['FareMinStay']['MinimumStayDay'] : 0,
                                            'Max' => (isset($class['BookingPolicy']['FareMaxStay'])) ? $class['BookingPolicy']['FareMaxStay']['MaximumStayDay'] : 0,
                                        ] : false,
                                    ] : false
                                ] : false,
                            ];
                        }


                        if (isset($item['Step1'])) { // در صورت وجود اولین توقف
                            $Step1 = [
                                'StopoverAirport' => $item['Step1']['AirportIataCode'],
                                'TimeFromStartToStop' => $item['Step1']['StopDurationInMinute'],
                                'StopTime' => $item['Step1']['StopDurationInMinute'],
                                'ArrivalDateTime' => $item['Step1']['ArrivalDateTime'],
                                'DepartureDateTime' => $item['Step1']['DepartureDateTime']
                            ];
                        }

                        if (isset($item['Step2'])) { // در صورت وجود دومین توقف
                            $Step2 = [
                                'StopoverAirport' => $item['Step2']['AirportIataCode'],
                                'TimeFromStartToStop' => $item['Step2']['StopDurationInMinute'],
                                'StopTime' => $item['Step2']['StopDurationInMinute'],
                                'ArrivalDateTime' => $item['Step2']['ArrivalDateTime'],
                                'DepartureDateTime' => $item['Step2']['DepartureDateTime']
                            ];
                        }

                        $Flights[] = [
                            'Service' => 'sepehr',
                            'ServiceId' => $dataItem['serviceId'],
                            'ServiceBranch' => $dataItem['serviceBranch'],
                            'DisplayableService' => $dataItem['isHub'] ? 'airplusHub' : 'sepehr',
                            'Verified' => ($item['SystemSupplier'] == 34 ? true : false),
                            'FlightType' => 'WebService',
                            'FlightRoute' => ($Origin['country'] != env('COUNTRY_CODE') || $Destination['country'] != env('COUNTRY_CODE')) ? 'International' : 'Internal',
                            'FlightNumber' => $item['FlightNumber'],
                            'Origin' => [
                                'Iata' => SepehrApi::getDetails('airport', $item['Origin']['Code'], $details),
                                'Terminal' => (!is_null($item['Origin']['Terminal'])) ? true : false
                            ],
                            'Destination' => [
                                'Iata' => SepehrApi::getDetails('airport', $item['Destination']['Code'], $details),
                                'Terminal' => (!is_null($item['Destination']['Terminal'])) ? true : false
                            ],
                            'DepartureDateTime' => $item['DepartureDateTime'] . ':00',
                            'ArrivalDateTime' => $item['ArrivalDateTime'] . ':00',
                            'Duration' => $item['Duration'],
                            'Aircraft' => SepehrApi::getDetails('aircraft', $item['Aircraft'], $details),
                            'Airline' => SepehrApi::getDetails('airline', $item['Airline'], $details),
                            'Remarks' => [
                                'AirlineSupplier' => $item['IsParvazSystemiAirline'],
                                'TourRequirement' => false,
                                'OneWayRequirement' => false,
                                'RoundtripRequirement' => false,
                                'PhoneRequirement' => false,
                                'Description' => (isset($item['CancelationPolicy'])) ? $item['CancelationPolicy'] : false,
                                'Special' => false,
                                'Warranty' => false,
                            ],
                            'ReturningFlight' => [
                                'AllowedReturnFlights' => [
                                    'FlightNumber' => false,
                                    'FlightDate' => false,
                                ],
                                'UnauthorizedReturnFlights' => false,
                                'ReturnOnlyFromTheAirlineOfOrigin' => false,
                                'ReturnFlightProvider' => false,
                                'DistanceToReturnFlight' => [
                                    'Min' => 0,
                                    'Max' => 0,
                                ],
                            ],
                            'Steps' => (isset($item['Step1']) or isset($item['Step2'])) ? [
                                (isset($Step1)) ? $Step1 : null,
                                (isset($Step2)) ? $Step2 : null
                            ] : false,
                            'Classes' => $Classes
                        ];
                    }
                }
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
            return ['Data' => $arr, 'Result' => $data];
        } else if ($goal == 'flight' && ($method == 'Book') and !is_null($data) && !is_null($subData)) {
            if (isset($data['LocalPnr'])) {
                foreach ($data['PassengerList'] as $item) {
                    $arr[] = [
                        'Status' => true,
                        'DepartureSegment' => [
                            'PNR' => [
                                'Service' => $data['LocalPnr'],
                                'Original' => $item['DepartureSegment']['OriginalPnr']
                            ],
                            'Origin' => [
                                'Iata' => $item['DepartureSegment']['OriginIataCode'],
                                'Terminal' => $subData['origin']['terminal']
                            ],
                            'Destination' => [
                                'Iata' => $item['DepartureSegment']['DestinationIataCode'],
                                'Terminal' => $subData['destination']['terminal']
                            ],
                            'FlightDateTime' => $subData['departureDateTime'],
                            'LocalTicketNumber' => $item['DepartureSegment']['LocalTicketNumber'],
                            'OriginalTicketNumber' => $item['DepartureSegment']['OriginalTicketNumber'],
                            'FlightNumber' => $item['DepartureSegment']['FlightNumber'],
                            'Description' => $subData['description']
                        ],
                        'ReturningSegment' => false,
                    ];
                }
            } else {
                $arr = [
                    'Status' => false,
                    'Time' => time(),
                    'Code' => '1501',
                    'Message' => $data
                ];
            }
            return ['Data' => $arr, 'Result' => $data];
        } elseif ($method == 'CurrentBalance') {
            $arr = [
                'Status' => true,
                'Time' => time(),
                'CurrencyCode' => 'IRR',
                'Information' => (int)$data['RemainedCredit']
            ];
            return $arr;
        } else {
            $arr = [
                'Status' => true,
                'Time' => time(),
                'Information' => (int)$data
            ];
            return $arr;
        }
    }

    static function ChangeCabinType($item, $details)
    {
        if (!$details) {
            switch ($item) {
                case 'Economy':
                    return 'Y';
                    break;
                case 'EconomyPlus':
                    return 'E';
                    break;
                case 'PremiumEconomy':
                    return 'P';
                    break;
                case 'Business':
                    return 'C';
                    break;
                case 'First':
                    return 'F';
                    break;
                default:
                    return 'Y';
                    break;
            }
        } else {
            switch ($item) {
                case 'Economy':
                    $tempClass = 'Y';
                    $tempClassTitleFa = 'اکونومی';
                    break;
                case 'EconomyPlus':
                    $tempClass = 'E';
                    $tempClassTitleFa = 'اکونومی پلاس';

                    break;
                case 'PremiumEconomy':
                    $tempClass = 'P';
                    $tempClassTitleFa = 'اکونومی ویژه';
                    break;
                case 'Business':
                    $tempClass = 'C';
                    $tempClassTitleFa = 'بیزینس کلاس';
                    break;
                case 'First':
                    $tempClass = 'F';
                    $tempClassTitleFa = 'فرست کلاس';
                    break;
                default:
                    $tempClass = 'Y';
                    $tempClassTitleFa = 'اکونومی';
                    break;
            }
            return [
                "iata" => $tempClass,
                "title" => [
                    "fa" => $tempClassTitleFa,
                    "en" => $item
                ]
            ];
        }
    }

    static function getSupplier($iata, $supplier, $branch = false)
    {
        if ($branch) {
            $tempBranch = DB::table('offices')->select('base_online')->where('id', str_replace('b2c-', '', $branch))->first();
            if (is_null($tempBranch->base_online) || str_replace('b2c-', '', $branch) == 1) {
                $supplier = 1;
                $iata = 'AirPlus';
            } else {
                return ["id" => $supplier]; // موقتا تامین کننده را خود تامین اصلی بر میگرداند
            }
        } else {
            return ["id" => $supplier]; // موقتا تامین کننده را خود تامین اصلی بر میگرداند
        }
        switch ($iata) {
            case "NV":
                return ["title" => "هواپیمائی کارون", "id" => 125];
                break;
            case "VR":
                return ["title" => "هواپیمائی وارش", "id" => 129];
                break;
            case "I3":
                return ["title" => "هواپیمائی آتا", "id" => 134];
                break;
            case "HH":
                return ["title" => "هواپیمائی تابان", "id" => 123];
                break;
            case "QB":
                return ["title" => "هواپیمائی قشم ایر", "id" => 131];
                break;
            case "ZV":
                return ["title" => "هواپیمائی زاگرس", "id" => 130];
                break;
            default:
                return ["id" => $supplier];
                break;
        }
    }

    static function getDetails($action, $data, $details = false, $branch = false, $isHub = false)
    {
        if ($branch) {
            $tempBranch = DB::table('offices')->select('base_online')->where('id', str_replace('b2c-', '', $branch))->first();
            if (is_null($tempBranch->base_online)) {
                $data = 1;
            }
        }
        if (!$details) {
            return $data;
        } else {
            if ($action == 'airline') {
                $iataAirline = DB::table('airlines')->select('id')->where('iata', $data)->orWhere('icao', $data)->first();
                return StaticController::dataRedis('airline', $iataAirline ? $iataAirline->id : $data);
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
                    } else return ["iata" => $data];
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
                    ->where('sepehr', $data)
                    ->where('status', 1)
                    ->first();

                if (is_null($supplierApi) || $isHub) $supplierApi = (object)["colleague" => 1];

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
                    } else
                        Redis::set('colleagues:' . $supplierApi->colleague, json_encode($supplier));
                }
                $supplier['base_id'] = $data;
                return $supplier;
            } else if ($action == 'system_supplier') {
                $supplierApi = DB::table('mapping_colleagues')
                    ->select('colleague')
                    ->where('sepehr', $data)
                    ->where('status', 1)
                    ->first();

                if (is_null($supplierApi)) $supplierApi = (object)["colleague" => 0];
                $supplier = json_decode(Redis::get('colleagues:' . $supplierApi->colleague), true);
                if (!$supplier) {
                    $supplier = DB::table('colleagues')->select('id', 'office as title_fa', 'office as title_en', 'first_name', 'last_name', 'credit_amount', 'status')->where('id', $supplierApi->colleague)->first();
                    if ($supplier) {
                        $supplier = [
                            "id" => $data,
                            "title_fa" => $supplier->title_fa,
                            "title_fa" => $supplier->title_en,
                            "first_name" => $supplier->first_name,
                            "last_name" => $supplier->last_name,
                            "credit_amount" => $supplier->credit_amount,
                            "status" => $supplier->status,
                            "base_id" => $data
                        ];
                        Redis::set('colleagues:' . $supplierApi->colleague, json_encode($supplier));
                    } else {
                        $supplier = [
                            "id" => $data,
                            "title_fa" => $data,
                            "title_en" => $data,
                            "first_name" => $data,
                            "last_name" => $data,
                            "credit_amount" => $data,
                            "status" => $data,
                            "base_id" => $data
                        ];
                    }
                }
                if (!isset($supplier['id'])) {
                    Redis::delete('colleagues:' . $supplierApi->colleague);
                }
                $supplier['base_id'] = $data;
                return $supplier;
            }
        }
    }

    static function accommodationResultAPI2ResultSys($goal, $method, $data, $subData = null, $details = false, $branch = false)
    {
        if ($method == 'get_content') {
            return $data;
        } else if ($method == 'search_by_city_and_date') {
            return $data;
        } else if ($method == 'lock') {
            Visa::addSystemReport($data);
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data
            ];
        } else if ($method == 'book') {
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data
            ];
        } else if ($method == 'get_status') {
            return [
                'Status' => true,
                'Time' => time(),
                'Information' => $data
            ];
        }
    }
}
