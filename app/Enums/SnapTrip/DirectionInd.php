<?php

namespace App\Enums\SnapTrip;

enum DirectionInd:int
{
    case OneWay = 1;
    case Return = 2;
    case Circle = 3;
    case OpenJaw = 4;

    public function label(): string
    {
        return match($this) {
            self::OneWay => 'یک‌طرفه',
            self::Return => 'رفت و برگشت',
            self::Circle => 'چرخشی',
            self::OpenJaw => 'اوپن‌جاو',
        };
    }
}
