<?php

namespace App\Services\OTA\Flights\Domain\ValueObjects\V2;

final readonly class CancellationPolicy
{
    public function __construct(
        public string $textFa,
        public string $textEn,
    ) {}

    public function toArray(): array
    {
        return [
            [
                'Culture' => 'en-US',
                'Text' => $this->textEn,
            ],
            [
                'Culture' => 'fa-IR',
                'Text' => $this->textFa,
            ],
        ];
    }
}