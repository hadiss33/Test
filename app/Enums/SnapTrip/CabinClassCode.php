<?php

namespace App\Enums\SnapTrip;

enum CabinClassCode:int
{
    case Y = 1;
    case S = 2;
    case C = 3;
    case J = 4;
    case F = 5;
    case P = 6;
    case Default = 100;

    public function label(): string
    {
        return match($this) {
            self::Y => 'اکونومی',
            self::S => 'اکونومی ویژه',
            self::C => 'بیزینس',
            self::J => 'جت',
            self::F => 'فرست کلاس',
            self::P => 'پریمیوم',
            self::Default => 'پیش‌فرض',
        };
    }
}
