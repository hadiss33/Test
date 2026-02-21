<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;
use App\Services\RouteSyncService;
use App\Enums\ServiceProviderEnum;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;

class SyncAirlineRoutesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; 
    public $tries = 2;

    protected $service;
    protected $airlineCode;

    public function __construct(string $service, ?string $airlineCode = null)
    {
        $this->service = $service;
        $this->airlineCode = $airlineCode;
        $this->queue = 'snailJob'; 
    }

    public function handle(FlightServiceRepositoryInterface $repository): void
    {
        $airlines = $this->airlineCode
            ? [$repository->getServiceByCode($this->service, $this->airlineCode)]
            : $repository->getActiveServices($this->service);

        $airlines = array_filter($airlines);

        if (empty($airlines)) {
            Log::warning("No airlines found for route sync. Service: {$this->service}");
            return;
        }

        $serviceEnum = ServiceProviderEnum::from($this->service);
        $providerClass = $serviceEnum->getProvider();

        foreach ($airlines as $config) {
            try {
                $provider = new $providerClass($config);
                
                $syncService = new RouteSyncService($provider, $config['code'], $this->service);

                $result = $syncService->sync();

                // Log::info("Route sync completed for {$config['code']} (Service: {$this->service})", $result);
            } catch (\Exception $e) {
                // Log::error("Route sync failed for {$config['code']}: " . $e->getMessage(), [
                //     'service' => $this->service,
                //     'trace' => $e->getTraceAsString()
                // ]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Log::error("SyncAirlineRoutesJob failed critically", [
        //     'service' => $this->service,
        //     'error' => $exception->getMessage()
        // ]);
    }
}