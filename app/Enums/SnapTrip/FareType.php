<?php

namespace App\Enums\SnapTrip;

enum FareType:int
{
    case Default = 1;
    case Publish = 2;
    case Private = 3;
    case WebFare = 4;
    case NegoCat35 = 5;
    case NegoCorporate = 6;
    case AmadeusNegoCorporate = 7;
    case PrivateCat15 = 8;
    case AmadeusNego = 9;
    case NetFare = 10;

    public function label(): string
    {
        return match($this) {
            self::Default => 'عادی',
            self::Publish => 'انتشاری',
            self::Private => 'خصوصی',
            self::WebFare => 'وب‌فِر',
            self::NegoCat35 => 'نِگو 35',
            self::NegoCorporate => 'نِگو شرکتی',
            self::AmadeusNegoCorporate => 'Amadeus Corporate',
            self::PrivateCat15 => 'Private Cat15',
            self::AmadeusNego => 'Amadeus Nego',
            self::NetFare => 'Net Fare',
        };
    }
}
