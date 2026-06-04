<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2;

use App\Services\OTA\Flights\Application\Contracts\FlightProviderInterface;
use App\Services\OTA\Flights\Domain\Repositories\ActiveRouteRepositoryInterface;
use App\Services\OTA\Flights\Domain\Repositories\FlightCredentialRepositoryInterface;
use App\Services\OTA\Flights\Infrastructure\Persistence\ApiMapper;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Auth\SepehrCredentialFactory;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Http\SepehrHttpClient;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Mapper\SepehrFinancialMapper;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Mapper\SepehrFlightMapper;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Mapper\SepehrSupplierMapper;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Repositories\SepehrActiveRouteRepository;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Repositories\SepehrCredentialRepository;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Legency\SepehrFlightLegency;
use Illuminate\Support\ServiceProvider;

class SepehrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Repositories
        $this->app->bind(FlightCredentialRepositoryInterface::class, SepehrCredentialRepository::class);
        $this->app->bind(ActiveRouteRepositoryInterface::class, SepehrActiveRouteRepository::class);

        // Auth
        $this->app->singleton(SepehrCredentialFactory::class);

        // HTTP
        $this->app->singleton(SepehrHttpClient::class);

        // Persistence
        $this->app->singleton(ApiMapper::class);

        // Mappers
        $this->app->singleton(SepehrSupplierMapper::class);
        $this->app->singleton(SepehrFinancialMapper::class);
        $this->app->singleton(SepehrFlightMapper::class);

        // Service
        $this->app->bind(FlightProviderInterface::class, SepehrFlightLegency::class);
        $this->app->singleton(SepehrFlightLegency::class);
    }
}