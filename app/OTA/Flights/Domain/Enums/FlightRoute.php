<?php

namespace App\Services\OTA\Flights\Domain\Enums;

enum FlightRoute: string
{
    case Internal      = 'Internal';
    case International = 'International';
}