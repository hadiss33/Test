<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;
use App\Services\RouteSyncService;
use App\Services\FlightProviders\NiraProvider;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;

class SyncAirlineRoutesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 2;

    protected $service;
    protected $airlineCode;

    public function __construct(string $service = 'nira', ?string $airlineCode = null)
    {
        $this->service = $service;
        $this->airlineCode = $airlineCode;
    }

    public function handle(FlightServiceRepositoryInterface $repository): void
    {
        Log::info('Starting route sync', [
            'service' => $this->service,
            'airline' => $this->airlineCode ?? 'all'
        ]);

        $airlines = $this->airlineCode
            ? [$repository->getServiceByCode($this->service, $this->airlineCode)]
            : $repository->getActiveServices($this->service);

        $airlines = array_filter($airlines);

        if (empty($airlines)) {
            Log::warning('No airlines found for route sync');
            return;
        }

        foreach ($airlines as $config) {
            try {
                $provider = new NiraProvider($config);
                $syncService = new RouteSyncService($provider, $config['code'], $this->service);

                $result = $syncService->sync();

                Log::info("Route sync completed for {$config['code']}", $result);

            } catch (\Exception $e) {
                Log::error("Route sync failed for {$config['code']}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SyncAirlineRoutesJob failed", [
            'service' => $this->service,
            'airline' => $this->airlineCode,
            'error' => $exception->getMessage()
        ]);
    }
}