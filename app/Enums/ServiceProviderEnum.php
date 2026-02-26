<?php

namespace App\Enums;

use App\Services\FlightProviders\{NiraProvider, SepehrProvider, RavisProvider};
use App\Services\FlightUpdaters\{NiraAnalyze, SepehrAnalyze, NiraFlightUpdater, SepehrFlightUpdater, RavisFlightUpdater, RavisAnalyze};


enum ServiceProviderEnum: string
{
    case Nira = 'nira';
    case Sepehr = 'sepehr';
    case Ravis = 'ravis';


    public function getProvider(): string
    {
        return match ($this) {
            self::Nira => NiraProvider::class,
            self::Sepehr => SepehrProvider::class,
            self::Ravis => RavisProvider::class,
        };
    }
    public function getAnalyzer(): ?string
    {
        return match ($this) {
            self::Nira => NiraAnalyze::class,
            self::Sepehr => SepehrAnalyze::class,
            self::Ravis => RavisAnalyze::class,
        };
    }

    public function getUpdater(): string
    {
        return match ($this) {
            self::Nira => NiraFlightUpdater::class,
            self::Sepehr => SepehrFlightUpdater::class,
            self::Ravis => RavisFlightUpdater::class,
        };
    }
}
