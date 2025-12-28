<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use App\Services\FlightCleanupService;
use Illuminate\Support\Facades\Log;


class UpdateFlightsPriorityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $priority;
    protected $service;
    protected $airlineCode;

    public function __construct(int $priority, string $service = 'nira', ?string $airlineCode = null)
    {
        $this->priority = $priority;
        $this->service = $service;
        $this->airlineCode = $airlineCode;
    }

    public function handle(): void
    {
        $repository = app(\App\Repositories\Contracts\FlightServiceRepositoryInterface::class);
        
        $airlines = $this->airlineCode
            ? [$repository->getServiceByCode($this->service, $this->airlineCode)]
            : $repository->getActiveServices($this->service);

        $airlines = array_filter($airlines);

        foreach ($airlines as $config) {
            try {
                $provider = new \App\Services\FlightProviders\NiraProvider($config);
                $updateService = new \App\Services\FlightUpdateService($provider, $config['code'], $this->service);
                
                $stats = $updateService->updateByPriority($this->priority);
                
                Log::info("Priority {$this->priority} update completed for {$config['code']}", $stats);
                
            } catch (\Exception $e) {
                Log::error("Priority update failed for {$config['code']}: " . $e->getMessage());
            }
        }
    }
}