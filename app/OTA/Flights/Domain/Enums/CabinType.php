<?php

namespace App\Services\OTA\Flights\Domain\Enums;

enum CabinType: string
{
    case Economy        = 'Economy';
    case EconomyPlus    = 'EconomyPlus';
    case PremiumEconomy = 'PremiumEconomy';
    case Business       = 'Business';
    case First          = 'First';
 
    public function iata(): string
    {
        return match($this) {
            self::Economy        => 'Y',
            self::EconomyPlus    => 'E',
            self::PremiumEconomy => 'P',
            self::Business       => 'C',
            self::First          => 'F',
        };
    }
 
    public function titleFa(): string
    {
        return match($this) {
            self::Economy        => 'اکونومی',
            self::EconomyPlus    => 'اکونومی پلاس',
            self::PremiumEconomy => 'اکونومی ویژه',
            self::Business       => 'بیزینس کلاس',
            self::First          => 'فرست کلاس',
        };
    }
 
    public static function fromApiValue(string $value): self
    {
        return self::tryFrom($value) ?? self::Economy;
    }
}