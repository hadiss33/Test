<?php

namespace App\Services\OTA\Flights\Domain\Enums;

enum PassengerType: string
{
    case ADULT = 'Adult';
    case CHILD = 'Child';
    case INFANT = 'Infant';
}