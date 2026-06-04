<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects;

final readonly class FarePrice
{
    public function __construct(
        public int       $baseFare,
        public int       $tax,
        public int       $markup,
        public int       $totalFare,
        public int       $payable,
        public float     $commissionPercentage,
        public int       $commissionFinal,
        public int       $commissionPrice,
    ) {}
}