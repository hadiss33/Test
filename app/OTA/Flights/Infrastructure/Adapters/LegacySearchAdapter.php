<?php

namespace App\Services\OTA\Flights\Infrastructure\Adapters;

use App\Services\OTA\Flights\Application\DTOs\SearchFlightResult;
use App\Services\OTA\Flights\Domain\Entities\Flight;
use App\Services\OTA\Flights\Domain\Entities\FlightClass;

class LegacySearchAdapter
{
    /**
     * تبدیل DTO مدرن به آرایه کلاسیک نسخه ۱
     * اضافه شدن پارامتر details$ برای هندل کردن ساختار فرودگاه‌ها در BaseService
     */
    public static function toArray(SearchFlightResult $result, bool $details = false): array
    {
        // پشتیبانی از هر دو حالت Data$ (آرایه) و data$ (آبجکت) در DTO
        $data = is_array($result->Data ?? null) ? $result->Data : (array) ($result->data ?? []);
        $rawResult = is_array($result->Result ?? null) ? $result->Result : (array) ($result->result ?? []);

        // هندل کردن خطای جستجو
        if (empty($data['Status']) || $data['Status'] === false) {
            return [
                'Data' => [
                    'Status'  => false,
                    'Time'    => $data['Time'] ?? time(),
                    'Code'    => $data['ErrorCode'] ?? '500',
                    'Message' => $data['ErrorMessage'] ?? 'خطایی رخ داده است',
                ],
                'Result' => $rawResult,
            ];
        }

        // پردازش پروازها
        $information = [];
        $flights = $data['Information'] ?? $data['flights'] ?? [];
        if (!empty($flights) && (is_array($flights) || is_iterable($flights))) {
            foreach ($flights as $flight) {
                $information[] = self::mapFlight($flight, $details);
            }
        }

        return [
            'Data' => [
                'Status'       => true,
                'Time'         => $data['Time'] ?? time(),
                'CurrencyCode' => $data['CurrencyCode'] ?? 'IRR',
                'Information'  => $information,
            ],
            'Result' => $rawResult,
        ];
    }

    /**
     * مپ کردن دقیق موجودیت Flight
     */
    private static function mapFlight(Flight $flight, bool $details): array
    {
        $classesArray = [];
        foreach ($flight->classes as $flightClass) {
            $classesArray[] = self::mapFlightClass($flightClass);
        }

        // 1. تبدیل امن مبدا و مقصد به آرایه
        $originRaw = self::toArraySafe($flight->origin);
        $destinationRaw = self::toArraySafe($flight->destination);

        // 2. جداسازی اطلاعات فرودگاه (iata) از ترمینال
        $originAirport = $originRaw['iata'] ?? $originRaw;
        $destinationAirport = $destinationRaw['iata'] ?? $destinationRaw;

        // 3. اعمال شرط details (اگر false باشد فقط رشته سه حرفی، اگر true باشد کل آرایه فرودگاه)
        $originIataData = $details ? $originAirport : ($originAirport['iata'] ?? $originAirport);
        $destinationIataData = $details ? $destinationAirport : ($destinationAirport['iata'] ?? $destinationAirport);

        return [
            'Service'            => $flight->service,
            'ServiceId'          => $flight->serviceId,
            'ServiceBranch'      => $flight->serviceBranch,
            'DisplayableService' => $flight->displayableService,
            'Verified'           => $flight->verified,
            // استخراج نام از Enumها
            'FlightType'         => $flight->flightType->name ?? $flight->flightType, 
            'FlightRoute'        => $flight->flightRoute->name ?? $flight->flightRoute, 
            'FlightNumber'       => $flight->flightNumber,
            
            // --- ساختار دقیق Origin و Destination منطبق با V1 ---
            'Origin'             => [
                'Iata'     => $originIataData,
                'Terminal' => $originRaw['terminal'] ?? false,
            ],
            'Destination'        => [
                'Iata'     => $destinationIataData,
                'Terminal' => $destinationRaw['terminal'] ?? false,
            ],
            // ---------------------------------------------------
            
            'DepartureDateTime'  => $flight->departureDateTime,
            'ArrivalDateTime'    => $flight->arrivalDateTime,
            'Duration'           => $flight->duration,
            'Aircraft'           => self::toArraySafe($flight->aircraft),
            'Airline'            => self::toArraySafe($flight->airline),
            
            'Remarks'            => [
                'AirlineSupplier'      => $flight->remarks->airlineSupplier ?? false,
                'TourRequirement'      => $flight->remarks->tourRequirement ?? false,
                'OneWayRequirement'    => $flight->remarks->oneWayRequirement ?? false,
                'RoundtripRequirement' => $flight->remarks->roundtripRequirement ?? false,
                'PhoneRequirement'     => $flight->remarks->phoneRequirement ?? false,
                'Description'          => $flight->remarks->description ?? '',
                'Special'              => $flight->remarks->special ?? false,
                'Warranty'             => $flight->remarks->warranty ?? false,
            ],
            
            'ReturningFlight'    => $flight->returningFlight,
            
            'Steps'              => $flight->steps ? array_map(function($step) {
                return [
                    'StopoverAirport'     => $step->airportIata ?? '',
                    'TimeFromStartToStop' => $step->stopDurationMinutes ?? 0,
                    'StopTime'            => $step->stopDurationMinutes ?? 0,
                    'ArrivalDateTime'     => $step->arrivalDateTime ?? '',
                    'DepartureDateTime'   => $step->departureDateTime ?? '',
                ];
            }, $flight->steps) : false,
            
            'Classes'            => $classesArray,
        ];
    }

    /**
     * مپ کردن دقیق موجودیت FlightClass
     */
    private static function mapFlightClass(FlightClass $class): array
    {
        $financial = self::toArraySafe($class->financial);
        $baseDataRaw = self::toArraySafe($class->baseData);

        return [
            'FlightStatus'      => $class->flightStatus,
            'Reservable'        => $class->reservable,
            'Status'            => $class->status,
            'CancelationPolicy' => $class->cancelationPolicy,
            'BookingPolicy'     => $class->bookingPolicy ? [
                'RestrictedForTour' => $class->bookingPolicy->restrictedForTour ?? false,
                'ReturningFlightMustNotEqualToAnyFlight' => $class->bookingPolicy->returningFlightMustNotEqualToAnyFlight ?? false,
                'ReturningFlightMustEqualToAnyFlight' => $class->bookingPolicy->returningFlightMustEqualToAnyFlight ?? false,
                'RestrictedReturningBySameAirline' => $class->bookingPolicy->restrictedReturningBySameAirline ?? false,
            ] : false,
            
            'Supplier'          => self::toArraySafe($class->supplier),
            'SystemSupplier'    => self::toArraySafe($class->systemSupplier),
            'FlightId'          => $class->flightId,
            'FareName'          => $class->fareName,
            'CabinType'         => self::toArraySafe($class->cabinType),
            'AvailableSeat'     => $class->availableSeat,
            'Rules'             => $class->rules,
            
            // بخش مالی با حروف بزرگ (PascalCase) برای مطابقت با V1
            'Financial'         => self::pascalCaseKeys($financial),
            
            'BaseData'          => [
                'Supplier' => [
                    'Supplier'       => self::toArraySafe($baseDataRaw['supplier']['supplier'] ?? $class->supplier),
                    'SystemSupplier' => self::toArraySafe($baseDataRaw['supplier']['systemSupplier'] ?? $class->systemSupplier),
                ],
                'Financial' => self::pascalCaseKeys(self::toArraySafe($baseDataRaw['financial'] ?? $financial)),
            ],
            
            // ساختار بار (Baggage) ثابت روی 3 مسافر
            'Baggage'           => [
                'Adult' => [
                    'Trunk' => [
                        'Number'      => $class->baggage->adult->trunkNumber ?? false,
                        'TotalWeight' => $class->baggage->adult->trunkWeight ?? false,
                    ],
                    'Hand' => [
                        'Number'      => $class->baggage->adult->handNumber ?? false,
                        'TotalWeight' => $class->baggage->adult->handWeight ?? false,
                    ]
                ],
                'Child' => [
                    'Trunk' => [
                        'Number'      => $class->baggage->child->trunkNumber ?? false,
                        'TotalWeight' => $class->baggage->child->trunkWeight ?? false,
                    ],
                    'Hand' => [
                        'Number'      => $class->baggage->child->handNumber ?? false,
                        'TotalWeight' => $class->baggage->child->handWeight ?? false,
                    ]
                ],
                'Infant' => [
                    'Trunk' => [
                        'Number'      => $class->baggage->infant->trunkNumber ?? false,
                        'TotalWeight' => $class->baggage->infant->trunkWeight ?? false,
                    ],
                    'Hand' => [
                        'Number'      => $class->baggage->infant->handNumber ?? false,
                        'TotalWeight' => $class->baggage->infant->handWeight ?? false,
                    ]
                ],
            ],
            
            'Remarks' => false,
        ];
    }

    /**
     * متد کمکی: تبدیل امن آبجکت‌ها و مدل‌ها به آرایه
     */
    private static function toArraySafe($data): array
    {
        if (is_object($data)) {
            if (method_exists($data, 'toArray')) {
                return $data->toArray();
            }
            return json_decode(json_encode($data), true) ?? [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * متد کمکی: بزرگ کردن حرف اول کلیدها (PascalCase) به صورت بازگشتی
     */
    private static function pascalCaseKeys(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = is_string($key) ? ucfirst($key) : $key;
            if (is_array($value)) {
                $value = self::pascalCaseKeys($value);
            }
            $result[$newKey] = $value;
        }
        return $result;
    }
}