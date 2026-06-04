<?php

namespace OTA\Flights\Domain\Enums;

enum ProviderName: string
{
    case Sepehr     = 'sepehr';
    case Ravis      = 'ravis';
    case Nira       = 'nira';
    case AirPlus    = 'airplus';
    case Charter724 = 'charter724';
}