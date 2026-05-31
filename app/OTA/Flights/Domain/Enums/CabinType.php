<?php

namespace App\Services\OTA\Flights\Domain\Enums;

enum CabinType: string
{
    case ECONOMY = 'Economy';
    case ECONOMY_PLUS = 'EconomyPlus';
    case PREMIUM_ECONOMY = 'PremiumEconomy';
    case BUSINESS = 'Business';
    case FIRST = 'First';

    public function toIata(): string
    {
        return match ($this) {
            self::ECONOMY => 'Y',
            self::ECONOMY_PLUS => 'E',
            self::PREMIUM_ECONOMY => 'P',
            self::BUSINESS => 'C',
            self::FIRST => 'F',
        };
    }

    public function toIataDetailed(): array
    {
        return [
            'iata' => $this->toIata(),
            'title' => [
                'fa' => match ($this) {
                    self::ECONOMY => 'اکونومی',
                    self::ECONOMY_PLUS => 'اکونومی پلاس',
                    self::PREMIUM_ECONOMY => 'اکونومی ویژه',
                    self::BUSINESS => 'بیزینس کلاس',
                    self::FIRST => 'فرست کلاس',
                },
                'en' => $this->value,
            ],
        ];
    }
}