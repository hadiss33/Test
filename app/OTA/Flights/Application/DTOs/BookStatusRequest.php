<?php

namespace App\Services\OTA\Flights\Application\DTOs;

final readonly class BookStatusRequest
{
    public function __construct(
        public int         $supplierId,
        public string      $localInventoryPnr,  // YourLocalInventoryPnr
        public int|string  $branch,
    ) {}
}