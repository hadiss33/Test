<?php

namespace App\Services\FlightProviders;

interface FlightProviderInterface
{
    /**
     * دریافت برنامه پروازها (برای Sync)
     */
    public function getFlightsSchedule(string $fromDate, string $toDate): array;

    /**
     * دریافت پروازهای موجود با قیمت ساده
     */
    public function getAvailabilityFare(string $origin, string $destination, string $date): array;

    /**
     * دریافت اطلاعات کامل نرخ
     */
    public function getFare(string $origin, string $destination, string $flightClass, string $date, string $flightNo = ''): ?array;

    /**
     * تبدیل Cap به تعداد صندلی
     */
    public function parseAvailableSeats(string $capacity): int;

    /**
     * تعیین وضعیت
     */
    public function determineStatus(string $capacity): string;
}