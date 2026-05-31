<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects\V2;

final readonly class Duration
{
    public function __construct(
        public int $minutes,
    ) {}
}