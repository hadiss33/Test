<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects\V2;

final readonly class Baggage
{
    public function __construct(
        public int $checkedQuantity,
        public int $checkedTotalWeight,
        public int $handQuantity,
        public int $handTotalWeight,
    ) {}

    public function toArray(): array
    {
        return [
            'Trunk' => [
                'Number' => $this->checkedQuantity,
                'TotalWeight' => $this->checkedTotalWeight,
            ],
            'Hand' => [
                'Number' => $this->handQuantity,
                'TotalWeight' => $this->handTotalWeight,
            ],
        ];
    }
}