<?php

namespace App\Services\OTA\Flights\Application\DTOs\V2;

readonly class LockRequest
{
    public function __construct(
        public FlightSegmentDTO $departureSegment,
        public ?FlightSegmentDTO $returningSegment,
        public int $adultCount,
        public int $childCount,
        public int $infantCount,
        public float $totalPayable // به صورت decimal/float چون مبلغ است
    ) {}
}