<?php

namespace App\Services\OTA\Flights\Application\DTOs;

final readonly class ActiveRoutesResult
{
    public function __construct(
        public bool  $status,
        /** @var array[] مسیرهایی که airport آنها در DB پیدا نشد */
        public array $unsubmittedItems,
    ) {}
}