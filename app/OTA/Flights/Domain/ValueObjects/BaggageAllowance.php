<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects;

final readonly class BaggageAllowance
{
    public function __construct(
        public int|false $trunkNumber,
        public int|false $trunkWeight,
        public int|false $handNumber,
        public int|false $handWeight,
    ) {}
}