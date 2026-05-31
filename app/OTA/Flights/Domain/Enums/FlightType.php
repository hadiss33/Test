<?php

namespace App\Services\OTA\Flights\Domain\Enums;

enum FlightType: string
{
    case CHARTER = 'Charter';
    case WEBSERVICE = 'WebService';
}