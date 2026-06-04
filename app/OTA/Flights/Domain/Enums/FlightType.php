<?php

namespace App\Services\OTA\Flights\Domain\Enums;

enum FlightType: string
{
    case Charter = 'Charter';
    case Nira = 'Nira';
    case WebService = 'WebService';

}
