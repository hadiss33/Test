<?php

namespace App\Services\OTA\Flights\Application\DTOs;

final readonly class LockFlightRequest
{
    public function __construct(
        public array       $lockData,
        public int         $supplierId, 
        public int|string  $branch,     
    ) {}
}