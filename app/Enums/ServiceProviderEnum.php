<?php

namespace App\Enums;
use App\Services\FlightProviders\NiraProvider;
use App\Services\FlightProviders\SepehrProvider;

enum ServiceProviderEnum: string
{
    case Nira = 'nira';
    case Sepehr = 'sepehr';

    public function getProvider(): string
    {
        return match ($this) {
            self::Nira => NiraProvider::class,
            self::Sepehr => SepehrProvider::class,

        };
    }
}
