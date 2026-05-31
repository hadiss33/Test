<?php

namespace App\Services\OTA\Flights\Application\DTOs\V2;

use App\Services\OTA\Flights\Domain\Entities\V2\FlightCollection;

final readonly class Response
{
    public function __construct(
        public bool $status,
        public int $time,
        public string $currencyCode,
        public FlightCollection $flights, // ✅ Entity Collection
        public array $rawResult = [],
    ) {}

    public function toLegacyFormat(): array
    {
        $information = [];
        foreach ($this->flights as $flight) {
            $information[] = $flight; // Mapper تبدیل به array می‌کنه
        }

        return [
            'Data' => [
                'Status' => $this->status,
                'Time' => $this->time,
                'CurrencyCode' => $this->currencyCode,
                'Information' => $information,
            ],
            'Result' => $this->rawResult,
        ];
    }
}