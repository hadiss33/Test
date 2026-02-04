<?php

namespace App\Enums\SnapTrip;

enum PassengerType:int
{
    case SeniorAdt = 0;
    case Adt = 1;
    case Chd = 2;
    case Inf = 3;

    public function label(): string
    {
        return match($this) {
            self::SeniorAdt => 'سالمند',
            self::Adt => 'بزرگسال',
            self::Chd => 'کودک',
            self::Inf => 'نوزاد',
        };
    }
}
