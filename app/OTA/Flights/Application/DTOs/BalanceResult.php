<?php

namespace App\Services\OTA\Flights\Application\DTOs;

final readonly class BalanceResult
{
    public function __construct(
        public bool   $status,
        public int    $remainedCredit,
        public string $currencyCode,
        public int    $time,
    ) {}
}