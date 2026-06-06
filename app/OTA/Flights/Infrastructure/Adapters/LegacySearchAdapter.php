<?php

namespace App\Services\OTA\Flights\Infrastructure\Adapters;

use App\Services\OTA\Flights\Application\DTOs\SearchFlightResult;
use App\Services\OTA\Flights\Domain\Entities\Flight;
use App\Services\OTA\Flights\Domain\Entities\FlightClass;
use App\Services\OTA\Flights\Domain\ValueObjects\Financial;
use App\Services\OTA\Flights\Domain\ValueObjects\FarePrice;
use App\Services\OTA\Flights\Domain\ValueObjects\Baggage;
use App\Services\OTA\Flights\Domain\ValueObjects\BookingPolicy;
use App\Services\OTA\Flights\Domain\ValueObjects\FlightRemarks;

/**
 * تبدیل SearchFlightResult (V2 DTOs/Entities) به آرایه دقیقاً مشابه V1
 * که BaseService::checkSearchFlightItem و بقیه کد انتظار دارن.
 *
 * ساختار مورد انتظار BaseService از هر flight item:
 * [
 *   'Service'            => string,
 *   'ServiceId'          => int,
 *   'ServiceBranch'      => int,
 *   'DisplayableService' => string,
 *   'Verified'           => bool,
 *   'FlightType'         => string,   // 'Charter' | 'WebService' | 'System'
 *   'FlightRoute'        => string,   // 'Internal' | 'International'
 *   'FlightNumber'       => string,
 *   'Origin'             => ['Iata' => string|array, 'Terminal' => bool],
 *   'Destination'        => ['Iata' => string|array, 'Terminal' => bool],
 *   'DepartureDateTime'  => string,
 *   'ArrivalDateTime'    => string|false,
 *   'Duration'           => int|false,
 *   'Aircraft'           => string|array,
 *   'Airline'            => string|array,  // BaseService خونه: $item['Airline']['iata']
 *   'Remarks'            => [
 *       'AirlineSupplier'      => bool,
 *       'TourRequirement'      => bool,
 *       'OneWayRequirement'    => bool,
 *       'RoundtripRequirement' => bool,
 *       'PhoneRequirement'     => bool,
 *       'Description'          => string|false,
 *       'Special'              => bool,
 *       'Warranty'             => bool,
 *   ],
 *   'ReturningFlight'    => array|false,
 *   'Steps'              => array|false,
 *   'Classes'            => [   // ← آرایه از classها (checkSearchFlightItem روی این usort می‌کنه)
 *       [
 *           'FlightStatus'      => bool,
 *           'Reservable'        => bool,
 *           'Status'            => string,
 *           'CancelationPolicy' => string|bool,
 *           'BookingPolicy'     => array|false,
 *           'Supplier'          => array,
 *           'SystemSupplier'    => array,
 *           'FlightId'          => string,
 *           'FareName'          => string|false,
 *           'CabinType'         => array,
 *           'AvailableSeat'     => int|false,
 *           'Rules'             => bool,
 *           'Financial'         => [    // ← flat array (نه object)
 *               'PriceAdditions' => ['Citizens' => int],
 *               'CommissionPaid' => ['Percentage' => bool, 'Transaction' => string, 'MembershipRight' => bool],
 *               'Adult'   => ['BaseFare'=>int, 'Tax'=>int, 'Markup'=>int, 'TotalFare'=>int, 'Payable'=>int, 'Commission'=>['Percentage'=>float,'Final'=>int,'Price'=>int]],
 *               'Child'   => [...],
 *               'Infant'  => [...],
 *           ],
 *           'BaseData'          => [
 *               'Supplier'  => ['Supplier' => array, 'SystemSupplier' => array],
 *               'Financial' => [  // ← همون ساختار Financial بالا
 *                   'Adult' => [...], 'Child' => [...], 'Infant' => [...], ...
 *               ],
 *           ],
 *           'Baggage'           => [
 *               'Adult'  => ['Trunk'=>['Number'=>int|false,'TotalWeight'=>int|false], 'Hand'=>[...]],
 *               'Child'  => [...],
 *               'Infant' => [...],
 *           ],
 *           'Remarks'           => false | [   // ← checkSearchFlightItem: $class['Remarks']['Inbound']['Special']
 *               'Inbound' => [
 *                   'AirlineSupplier'      => bool,
 *                   'TourRequirement'      => bool,
 *                   'OneWayRequirement'    => bool,
 *                   'RoundtripRequirement' => bool,
 *                   'PhoneRequirement'     => bool,
 *                   'Description'          => string|false,
 *                   'Special'              => bool,
 *                   'Warranty'             => bool,
 *               ],
 *               'Outbound' => false|array,
 *           ],
 *       ],
 *   ],
 * ]
 */
class LegacySearchAdapter
{
    /**
     * تبدیل SearchFlightResult به آرایه V1-compatible
     *
     * @param SearchFlightResult $result
     * @param bool $details اگه true باشه Iata کامل array، اگه false باشه string سه‌حرفی
     */
    public static function toArray(SearchFlightResult $result, bool $details = false): array
    {
        $data      = is_array($result->Data ?? null) ? $result->Data : (array) ($result->data ?? []);
        $rawResult = is_array($result->Result ?? null) ? $result->Result : (array) ($result->result ?? []);

        // خطا
        if (empty($data['Status']) || $data['Status'] === false) {
            return [
                'Data' => [
                    'Status'  => false,
                    'Time'    => $data['Time'] ?? time(),
                    'Code'    => $data['ErrorCode'] ?? $data['Code'] ?? '500',
                    'Message' => $data['ErrorMessage'] ?? $data['Message'] ?? 'خطایی رخ داده است',
                ],
                'Result' => $rawResult,
            ];
        }

        $flights     = $data['Information'] ?? $data['flights'] ?? [];
        $information = [];

        foreach ($flights as $flight) {
            $information[] = self::mapFlight($flight, $details);
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

    // ─── Flight ──────────────────────────────────────────────────────────────

    private static function mapFlight(Flight $flight, bool $details): array
    {
        // Origin / Destination
        $originRaw      = self::toArraySafe($flight->origin);
        $destinationRaw = self::toArraySafe($flight->destination);

        $originIata = self::resolveIata($originRaw['iata'] ?? $originRaw, $details);
        $destIata   = self::resolveIata($destinationRaw['iata'] ?? $destinationRaw, $details);

        // Classes — هر FlightClass entity → flat array
        $classes = [];
        foreach ($flight->classes as $class) {
            $classes[] = self::mapClass($class, $details);
        }

        return [
            'Service'            => $flight->service,
            'ServiceId'          => $flight->serviceId,
            'ServiceBranch'      => $flight->serviceBranch,
            'DisplayableService' => $flight->displayableService,
            'Verified'           => $flight->verified,
            'FlightType'         => $flight->flightType instanceof \BackedEnum
                                        ? $flight->flightType->value
                                        : (string) $flight->flightType,
            'FlightRoute'        => $flight->flightRoute instanceof \BackedEnum
                                        ? $flight->flightRoute->value
                                        : (string) $flight->flightRoute,
            'FlightNumber'       => $flight->flightNumber,
            'Origin'             => [
                'Iata'     => $originIata,
                'Terminal' => $originRaw['terminal'] ?? false,
            ],
            'Destination'        => [
                'Iata'     => $destIata,
                'Terminal' => $destinationRaw['terminal'] ?? false,
            ],
            'DepartureDateTime'  => $flight->departureDateTime,
            'ArrivalDateTime'    => $flight->arrivalDateTime,
            'Duration'           => $flight->duration,
            'Aircraft'           => self::toArraySafe($flight->aircraft),
            'Airline'            => self::toArraySafe($flight->airline),
            'Remarks'            => self::mapFlightRemarks($flight->remarks),
            'ReturningFlight'    => $flight->returningFlight,
            'Steps'              => $flight->steps
                                        ? array_map(fn($s) => self::mapStep($s), $flight->steps)
                                        : false,
            'Classes'            => $classes,
        ];
    }

    // ─── FlightClass ─────────────────────────────────────────────────────────

    private static function mapClass(FlightClass $class, bool $details): array
    {
        $financial = self::mapFinancial($class->financial);

        // baseData['Financial'] می‌تونه Financial object باشه، array باشه، یا null
        $baseFinancialRaw = $class->baseData['Financial'] ?? null;
        if ($baseFinancialRaw instanceof Financial) {
            $baseFinancial = self::mapFinancial($baseFinancialRaw);
        } elseif (is_array($baseFinancialRaw) && isset($baseFinancialRaw['Adult'])) {
            $baseFinancial = self::ensureFinancialStructure($baseFinancialRaw);
        } else {
            // fallback: همون financial اصلی
            $baseFinancial = $financial;
        }

        return [
            'FlightStatus'      => $class->flightStatus,
            'Reservable'        => $class->reservable,
            'Status'            => $class->status,
            'CancelationPolicy' => $class->cancelationPolicy,
            'BookingPolicy'     => self::mapBookingPolicy($class->bookingPolicy),

            'Supplier'          => self::toArraySafe($class->supplier),
            'SystemSupplier'    => self::toArraySafe($class->systemSupplier),

            'FlightId'          => $class->flightId,
            'FareName'          => $class->fareName,
            'CabinType'         => self::toArraySafe($class->cabinType),
            'AvailableSeat'     => $class->availableSeat,
            'Rules'             => $class->rules,

            'Financial'         => $financial,

            'BaseData'          => [
                'Supplier' => [
                    'Supplier'       => self::toArraySafe(
                        $class->baseData['Supplier']['Supplier'] ?? $class->supplier
                    ),
                    'SystemSupplier' => self::toArraySafe(
                        $class->baseData['Supplier']['SystemSupplier'] ?? $class->systemSupplier
                    ),
                ],
                'Financial' => $baseFinancial,
            ],

            'Baggage'           => self::mapBaggage($class->baggage),

            // ← مهم: checkSearchFlightItem روی $class['Remarks']['Inbound']['Special'] و بقیه دسترسی داره
            'Remarks'           => self::mapClassRemarks($class->inboundRemarks, $class->outboundPolicy),
        ];
    }

    // ─── Financial ───────────────────────────────────────────────────────────

    /**
     * Financial entity → آرایه V1
     */
    private static function mapFinancial(Financial $financial): array
    {
        return [
            'PriceAdditions' => [
                'Citizens' => $financial->citizenPriceAddition ?? 0,
            ],
            'CommissionPaid' => [
                'Percentage'      => $financial->commissionPercentage ?? false,
                'Transaction'     => $financial->transaction ?? '0',
                'MembershipRight' => $financial->membershipRight ?? false,
            ],
            'Adult'   => self::mapFarePrice($financial->adult),
            'Child'   => self::mapFarePrice($financial->child),
            'Infant'  => self::mapFarePrice($financial->infant),
        ];
    }

    private static function mapFarePrice(FarePrice $fare): array
    {
        return [
            'BaseFare'  => $fare->baseFare,
            'Tax'       => $fare->tax,
            'Markup'    => $fare->markup,
            'TotalFare' => $fare->totalFare,
            'Payable'   => $fare->payable,
            'Commission' => [
                'Percentage' => $fare->commissionPercentage,
                'Final'      => $fare->commissionFinal,
                'Price'      => $fare->commissionPrice,
            ],
        ];
    }

    private static function emptyFinancial(): array
    {
        $emptyFare = [
            'BaseFare' => 0, 'Tax' => 0, 'Markup' => 0,
            'TotalFare' => 0, 'Payable' => 0,
            'Commission' => ['Percentage' => false, 'Final' => false, 'Price' => false],
        ];
        return [
            'PriceAdditions' => ['Citizens' => 0],
            'CommissionPaid' => ['Percentage' => false, 'Transaction' => '0', 'MembershipRight' => false],
            'Adult'   => $emptyFare,
            'Child'   => $emptyFare,
            'Infant'  => $emptyFare,
        ];
    }

    /**
     * اگه financial array از V1 format داره ولی ممکنه PriceAdditions/CommissionPaid نداشته باشه
     */
    private static function ensureFinancialStructure(array $f): array
    {
        return [
            'PriceAdditions' => $f['PriceAdditions'] ?? ['Citizens' => 0],
            'CommissionPaid' => $f['CommissionPaid'] ?? [
                'Percentage' => false, 'Transaction' => '0', 'MembershipRight' => false
            ],
            'Adult'   => $f['Adult']   ?? self::emptyFare(),
            'Child'   => $f['Child']   ?? self::emptyFare(),
            'Infant'  => $f['Infant']  ?? self::emptyFare(),
        ];
    }

    private static function emptyFare(): array
    {
        return [
            'BaseFare' => 0, 'Tax' => 0, 'Markup' => 0,
            'TotalFare' => 0, 'Payable' => 0,
            'Commission' => ['Percentage' => false, 'Final' => false, 'Price' => false],
        ];
    }

    // ─── Remarks ─────────────────────────────────────────────────────────────

    /**
     * Flight-level Remarks (نه Class-level)
     * BaseService: $item['Remarks']['TourRequirement'] و غیره
     */
    private static function mapFlightRemarks(FlightRemarks $remarks): array
    {
        return [
            'AirlineSupplier'      => $remarks->airlineSupplier,
            'TourRequirement'      => $remarks->tourRequirement,
            'OneWayRequirement'    => $remarks->oneWayRequirement,
            'RoundtripRequirement' => $remarks->roundtripRequirement,
            'PhoneRequirement'     => $remarks->phoneRequirement,
            'Description'          => $remarks->description,
            'Special'              => $remarks->special,
            'Warranty'             => $remarks->warranty,
        ];
    }

    /**
     * Class-level Remarks
     * BaseService: $class['Remarks']['Inbound']['Special']
     *              $class['Remarks']['Inbound']['TourRequirement']
     *              $class['Remarks']['Outbound']['TourRequirement']
     *
     * اگه inboundRemarks نداشتیم → false (checkSearchFlightItem این حالت رو handle می‌کنه)
     */
    private static function mapClassRemarks(
        FlightRemarks|false $inbound,
        BookingPolicy|false $outboundPolicy
    ): array|false {
        if ($inbound === false) {
            return false;
        }

        return [
            'Inbound' => [
                'AirlineSupplier'      => $inbound->airlineSupplier,
                'TourRequirement'      => $inbound->tourRequirement,
                'OneWayRequirement'    => $inbound->oneWayRequirement,
                'RoundtripRequirement' => $inbound->roundtripRequirement,
                'PhoneRequirement'     => $inbound->phoneRequirement,
                'Description'          => $inbound->description,
                'Special'              => $inbound->special,
                'Warranty'             => $inbound->warranty,
            ],
            'Outbound' => $outboundPolicy
                ? self::mapOutboundPolicy($outboundPolicy)
                : false,
        ];
    }

    private static function mapOutboundPolicy(BookingPolicy $policy): array
    {
        return [
            'AllowedReturnFlights'          => $policy->returningFlightMustEqualList,
            'UnauthorizedReturnFlights'     => $policy->returningFlightMustNotEqualList,
            'ReturnOnlyFromTheAirlineOfOrigin' => $policy->restrictedReturningBySameAirline,
            'ReturnFlightProvider'          => $policy->returnFlightSupplierId,
            'DistanceToReturnFlight'        => [
                'Min' => $policy->fareMinStayDays,
                'Max' => $policy->fareMaxStayDays,
            ],
        ];
    }

    // ─── BookingPolicy ───────────────────────────────────────────────────────

    private static function mapBookingPolicy(BookingPolicy|false $policy): array|false
    {
        if ($policy === false) return false;

        return [
            'RestrictedForTour'                        => $policy->restrictedForTour,
            'ReturningFlightMustNotEqualToAnyFlight'   => $policy->returningFlightMustNotEqualToAnyFlight,
            'ReturningFlightMustEqualToAnyFlight'      => $policy->returningFlightMustEqualToAnyFlight,
            'RestrictedReturningBySameAirline'         => $policy->restrictedReturningBySameAirline,
            'ReturningFlightMustEqualList'             => $policy->returningFlightMustEqualList,
            'ReturningFlightMustNotEqualList'          => $policy->returningFlightMustNotEqualList,
            'FareMinStay'                              => $policy->fareMinStayDays
                ? ['MinimumStayDay' => $policy->fareMinStayDays] : false,
            'FareMaxStay'                              => $policy->fareMaxStayDays
                ? ['MaximumStayDay' => $policy->fareMaxStayDays] : false,
        ];
    }

    // ─── Baggage ─────────────────────────────────────────────────────────────

    private static function mapBaggage(Baggage $baggage): array
    {
        return [
            'Adult' => [
                'Trunk' => [
                    'Number'      => $baggage->adult->trunkNumber,
                    'TotalWeight' => $baggage->adult->trunkWeight,
                ],
                'Hand' => [
                    'Number'      => $baggage->adult->handNumber,
                    'TotalWeight' => $baggage->adult->handWeight,
                ],
            ],
            'Child' => [
                'Trunk' => [
                    'Number'      => $baggage->child->trunkNumber,
                    'TotalWeight' => $baggage->child->trunkWeight,
                ],
                'Hand' => [
                    'Number'      => $baggage->child->handNumber,
                    'TotalWeight' => $baggage->child->handWeight,
                ],
            ],
            'Infant' => [
                'Trunk' => [
                    'Number'      => $baggage->infant->trunkNumber,
                    'TotalWeight' => $baggage->infant->trunkWeight,
                ],
                'Hand' => [
                    'Number'      => $baggage->infant->handNumber,
                    'TotalWeight' => $baggage->infant->handWeight,
                ],
            ],
        ];
    }

    // ─── Steps ───────────────────────────────────────────────────────────────

    private static function mapStep(\App\Services\OTA\Flights\Domain\ValueObjects\Stopover $step): array
    {
        return [
            'StopoverAirport'     => $step->airportIata,
            'TimeFromStartToStop' => $step->stopDurationMinutes,
            'StopTime'            => $step->stopDurationMinutes,
            'ArrivalDateTime'     => $step->arrivalDateTime,
            'DepartureDateTime'   => $step->departureDateTime,
        ];
    }

    // ─── Iata resolver ───────────────────────────────────────────────────────

    /**
     * details=false → string سه‌حرفی
     * details=true  → array کامل فرودگاه
     *
     * BaseService خط 263: if ($item['Origin']['Iata'] == $data['OriginIataCode'])
     * یعنی وقتی details=false باید string مستقیم باشه
     *
     * BaseService خط 5013: $item['Origin']['Iata']['iata'] ?? $item['Origin']['Iata']
     * یعنی هر دو حالت handle شده
     */
    private static function resolveIata(mixed $iata, bool $details): mixed
    {
        if (!$details) {
            // string مستقیم
            if (is_string($iata)) return $iata;
            if (is_array($iata)) return $iata['iata'] ?? $iata;
            return $iata;
        }
        // details=true → array کامل
        return is_array($iata) ? $iata : ['iata' => $iata];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private static function toArraySafe(mixed $data): array
    {
        if (is_object($data)) {
            if (method_exists($data, 'toArray')) return $data->toArray();
            return json_decode(json_encode($data), true) ?? [];
        }
        return is_array($data) ? $data : [];
    }
}