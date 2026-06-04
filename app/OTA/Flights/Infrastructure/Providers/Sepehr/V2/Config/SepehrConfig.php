<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Config;

class SepehrConfig
{
    // ─── Flight endpoints ───────────────────────────────────────────────────────

    public const ENDPOINTS = [
        // Availability
        'SearchByRouteAndDate'        => '/api/Partners/Flight/Availability/V12/SearchByRouteAndDate',
        'GetByDateRangeWebservice_1'  => '/api/Partners/Flight/Availability/V12/DateRange/Webservice/GetRange1',
        'GetByDateRangeWebservice_2'  => '/api/Partners/Flight/Availability/V12/DateRange/Webservice/GetRange2',
        'GetByDateRangeWebservice_3'  => '/api/Partners/Flight/Availability/V12/DateRange/Webservice/GetRange3',
        'GetByDateRangeWebservice_4'  => '/api/Partners/Flight/Availability/V12/DateRange/Webservice/GetRange4',
        'GetByDateRangeWebservice_5'  => '/api/Partners/Flight/Availability/V12/DateRange/Webservice/GetRange5',
        'GetByDateRangeCharter'       => '/api/Partners/Flight/Availability/V12/DateRange/GetCharterFlights',
        'GetFlightCount'              => '/api/Partners/Flight/Availability/V12/DateRange/GetFlightCount',
        'GetActiveRoutesCharter'      => '/api/Partners/Flight/Availability/V12/ActiveRoutes/GetCharterActiveRoutes',
        'GetActiveRoutesWebservice'   => '/api/Partners/Flight/Availability/V12/ActiveRoutes/GetWebserviceActiveRoutes',
        'GetActiveRoutes'             => '/api/Partners/Flight/Availability/V16/GetActiveRoutes',

        // Booking
        'Lock'                        => '/api/Partners/Flight/Booking/V10/Lock',
        'Book'                        => '/api/Partners/Flight/Booking/V10/Book',
        'ReleaseLock'                 => '/api/Partners/Flight/Booking/V9/ReleaseLock',

        // Refund
        'RetriveBooking'              => '/api/Partners/Flight/Refund/V3/RetrieveBooking',
        'GetPenalty'                  => '/api/Partners/Flight/Refund/V3/GetPenalty',
        'DoRefund'                    => '/api/Partners/Flight/Refund/V3/DoRefund',
        'RefundGetStatus'             => '/api/Partners/Flight/Refund/V3/GetStatus',

        // Changed Schedule
        'ChangedSchedulePassengersCharter'    => '/api/Partners/ChangedSchedulePassengers/V1/Charter/Get',
        'ChangedSchedulePassengersWebservice' => '/api/Partners/ChangedSchedulePassengers/V1/Webservice/Get',

        // Retrieve Booking
        'BookGetStatus'               => '/api/Partners/Flight/RetrieveBooking/V1/GetStatus',
        'BookGetHistory'              => '/api/Partners/Flight/RetrieveBooking/V1/GetHistory',

        // Generic
        'CurrentBalance'              => '/api/Partners/Generic/V7/CurrentBalance',
        'GetActiveRoutesLegacy'       => '/api/Partners/Flight/Availability/V16/GetActiveRoutes',
        'EventRetrievalB2M'           => '/api/ThirdParties/EventRetrieval/B2M/V1/GetEvents',
        'EventRetrievalB2B'           => '/api/ThirdParties/EventRetrieval/B2B/V1/GetEvents',
        'FlightDeepLink'              => '/api/DeepLink/Flight/Availability/Oneway/SpecificFlight/V1',
    ];

    // ─── Tour endpoints ─────────────────────────────────────────────────────────

    public const TOUR_ENDPOINTS = [
        'GetContent'          => '/api/Hotel/GetContent/V2',
        'SearchByRouteAndDate'=> '/api/Partners/Tour/Availability/V2/SearchPackage',
        'Lock'                => '/api/Partners/Tour/Booking/V1/Lock',
        'Book'                => '/api/Partners/Tour/Booking/V1/Book',
    ];

    // ─── Accommodation endpoints ────────────────────────────────────────────────

    public const ACCOMMODATION_ENDPOINTS = [
        'get_content'           => '/api/Hotel/GetContent/V3',
        'search_by_city_and_date' => '/api/Partners/Hotel/Availability/V3/SearchByCityAndDate',
        'get_by_date_range'     => '/api/Partners/Hotel/Availability/V3/GetByDateRange',
        'lock'                  => '/api/Partners/Hotel/Booking/V4/Lock',
        'book'                  => '/api/Partners/Hotel/Booking/V4/Book',
        'get_status'            => '/api/Partners/Hotel/RetrieveBooking/V1/GetStatus',
    ];

    // ─── Error messages ─────────────────────────────────────────────────────────

    public const ERROR_MESSAGES = [
        'Error1001-FlightNotFound'           => 'پروازی با اطلاعات درخواستی پیدا نشد.',
        'Error1002-NoEnoughSeatAvailable'    => 'تعداد صندلی درخواستی در پرواز موجود نمی باشد.',
        'Error1003-NoEnoughSeatAvailable'    => 'تعداد صندلی درخواستی در پرواز موجود نمی باشد.',
        'Error1004-NoEnoughCredit'           => 'باقی مانده اعتبار حساب برای انجام این رزرو کافی نیست.',
        'Error1005-CreditDueDateReached'     => 'مهلت پرداخت بدهی به اتمام رسیده است.',
        'Error1006-FareNotFound'             => 'کلاس پروازی با اسم fare درخواستی پیدا نشد.',
        'Error1007-DuplicateClientPnr'       => 'YourLocalInventoryPnr تکراری است.',
        'Error1008-PenaltyIsNotDefinedException' => 'جریمه توسط تامین کننده تعریف نشده است.',
        'Error1009-PenaltyMismatchException' => 'مبلغ جریمه ارسال شده مطابقت ندارد.',
        'Error1010-LockReleased'             => 'قفل رزرو آزاد شده است.',
        'Error1011-FlightTimeMismatch'       => 'ساعت پرواز مطابقت ندارد.',
        'Error1012-ForbiddenNationality'     => 'پذیرش این اتباع در این مسیر ممنوع است.',
        'Error1013-FlightLockCountLimit'     => 'حداکثر 9 صندلی قابل قفل است.',
        'Exception'                          => 'خطای نامشخص.',
    ];

    public static function getEndpoint(string $method): string
    {
        return self::ENDPOINTS[$method]
            ?? throw new \InvalidArgumentException("Unknown Sepehr endpoint: {$method}");
    }

    public static function getErrorMessage(string $errorKey): string
    {
        return self::ERROR_MESSAGES[$errorKey] ?? self::ERROR_MESSAGES['Exception'];
    }

    /** آیا این endpoint از Credential wrapper استفاده می‌کند */
    public static function usesCredentialWrapper(string $method): bool
    {
        return in_array($method, ['BookGetStatus', 'BookGetHistory']);
    }
}