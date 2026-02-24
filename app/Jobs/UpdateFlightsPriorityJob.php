<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Enums\ServiceProviderEnum;
use App\Services\FlightUpdaters\NiraFlightUpdater;

class UpdateFlightsPriorityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 2;
    public $maxExceptions = 1;

    protected $priority;
    protected $service;
    protected $airlineCode;

    public function __construct(int $priority, string $service = 'nira', ?string $airlineCode = null)
    {
        $this->priority    = $priority;
        $this->service     = $service;
        $this->airlineCode = $airlineCode;
        $this->queue       = 'queueJob';
    }

    public function handle(): void
    {
        $repository = app(\App\Repositories\Contracts\FlightServiceRepositoryInterface::class);

        $serviceEnum   = ServiceProviderEnum::from($this->service);
        $providerClass = $serviceEnum->getProvider();
        $updaterClass  = $serviceEnum->getUpdater();


        if ($this->service === 'nira' && $updaterClass === NiraFlightUpdater::class) {
            $this->handleNira($repository, $providerClass);
            return;
        }

        $airlines = $this->airlineCode
            ? [$repository->getServiceByCode($this->service, $this->airlineCode)]
            : $repository->getActiveServices($this->service);

        $airlines = array_filter($airlines);

        if (empty($airlines)) {
            return;
        }

        foreach ($airlines as $config) {
            try {
                $provider = new $providerClass($config);
                $updater  = new $updaterClass($provider, $config['code'] ?? null, $this->service);
                $updater->updateByPeriod($this->priority);
            } catch (\Exception $e) {
                // Log::error(...)
            }
        }
    }

    protected function handleNira($repository, string $providerClass): void
    {
        $allConfigs = $this->airlineCode
            ? array_filter([$repository->getServiceByCode($this->service, $this->airlineCode)])
            : $repository->getActiveServices($this->service);

        $allConfigs = array_values(array_filter($allConfigs));

        if (empty($allConfigs)) {
            return;
        }

        try {
            $firstProvider = new $providerClass($allConfigs[0]);
            $updater       = new NiraFlightUpdater($firstProvider, $allConfigs[0]['code'] ?? null, $this->service);
            $updater->withAllConfigs($allConfigs);
            $updater->updateByPeriod($this->priority);
        } catch (\Exception $e) {
            // Log::error(...)
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Log::error(...)
    }
}