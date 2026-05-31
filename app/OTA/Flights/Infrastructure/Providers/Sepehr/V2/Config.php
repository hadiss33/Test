<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2;

final class Config
{
    public const BASE_URL = 'https://{SupplierWebsiteUrl}';
    
    public const ENDPOINTS = [
        'SearchByRouteAndDate' => '/api/Partners/Flight/Availability/V17/SearchByRouteAndDate',
        'Lock' => '/api/Partners/Flight/Booking/V17/Lock',
        'Book' => '/api/Partners/Flight/Booking/V17/Book',
        'ReleaseLock' => '/api/Partners/Flight/Booking/V17/ReleaseLock',
        'RetriveBooking' => '/api/Partners/Flight/Refund/V17/RetrieveBooking',
        'GetPenalty' => '/api/Partners/Flight/Refund/V17/GetPenalty',
        'DoRefund' => '/api/Partners/Flight/Refund/V17/DoRefund',
        'RefundGetStatus' => '/api/Partners/Flight/Refund/V17/GetStatus',
        'ChangedSchedulePassengersCharter' => '/api/Partners/ChangedSchedulePassengers/V17/Charter/Get',
        'ChangedSchedulePassengersWebservice' => '/api/Partners/ChangedSchedulePassengers/V17/Webservice/Get',
        'BookGetStatus' => '/api/Partners/Flight/RetrieveBooking/V17/GetStatus',
        'BookGetHistory' => '/api/Partners/Flight/RetrieveBooking/V17/GetHistory',
        'CurrentBalance' => '/api/Partners/Generic/V17/CurrentBalance',
        'GetActiveRoutes' => '/api/Partners/Flight/Availability/V17/GetActiveRoutes',
    ];

    public static function getEndpoint(string $method): string
    {
        return self::ENDPOINTS[$method] ?? throw new \InvalidArgumentException("Unknown method: {$method}");
    }
}