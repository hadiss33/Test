<?php

namespace App\Services\OTA\Flights\Application\DTOs;


final readonly class SearchFlightResult
{
    public function __construct(
        public array    $Data,
        public array     $Result,
    ) {}
}