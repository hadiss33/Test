<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects\V2;

final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency = 'IRR',
    ) {}

    public function add(Money $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException('Cannot add different currencies');
        }
        return new self($this->amount + $other->amount, $this->currency);
    }

    public function percentage(float $percent): self
    {
        return new self((int) round($this->amount * $percent / 100), $this->currency);
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}