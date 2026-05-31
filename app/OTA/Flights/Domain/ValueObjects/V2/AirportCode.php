<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects\V2;

final readonly class AirportCode
{
    public function __construct(
        public string $iata,
        public ?string $terminal = null,
    ) {}

    public function toArray(bool $detailed = false): array|string
    {
        if (!$detailed) {
            return $this->iata;
        }

        return [
            'iata' => $this->iata,
            'terminal' => !is_null($this->terminal),
        ];
    }
}