<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects\V2;

use App\Services\OTA\Flights\Domain\Enums\PassengerType;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\Money;

final readonly class PassengerFare
{
    public function __construct(
        public PassengerType $type,
        public Money $baseFare,
        public Money $tax,
        public Money $totalFare,
        public Money $commission,
        public Money $markup,
        public Money $payable,
    ) {}

    public function toArray(): array
    {
        return [
            'BaseFare' => $this->baseFare->amount,
            'Tax' => $this->tax->amount,
            'TotalFare' => $this->totalFare->amount,
            'Commission' => $this->commission->amount,
            'Markup' => $this->markup->amount,
            'Payable' => $this->payable->amount,
        ];
    }
}