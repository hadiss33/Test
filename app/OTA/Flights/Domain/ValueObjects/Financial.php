<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects;

final readonly class Financial
{
    public function __construct(
        public int            $citizenPriceAddition,
        public bool           $commissionPercentage,
        public string         $transaction,
        public bool           $membershipRight,
        public FarePrice      $adult,
        public FarePrice      $child,
        public FarePrice      $infant,
        public RoundtripFare|false $roundtripFare = false,
    ) {}
}