<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects;

final readonly class Baggage
{
    public function __construct(
        public BaggageAllowance $adult,
        public BaggageAllowance $child,
        public BaggageAllowance $infant,
    ) {}
}