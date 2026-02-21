<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Log;
use App\Enums\ServiceProviderEnum;

class UpdateFlightsPriorityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 2;
    public $maxExceptions = 1;

    protected $priority;
    protected $service;
    protected $airlineCode;

    public function __construct(int $priority, string $service = 'nira', ?string $airlineCode = null)
    {
        $this->priority = $priority;
        $this->service = $service;
        $this->airlineCode = $airlineCode;
        $this->queue = 'queueJob';
    }

    public function handle(): void
    {
        $repository = app(\App\Repositories\Contracts\FlightServiceRepositoryInterface::class);

        $airlines = $this->airlineCode
            ? [$repository->getServiceByCode($this->service, $this->airlineCode)]
            : $repository->getActiveServices($this->service);

        $airlines = array_filter($airlines);

        // Check if airlines list is empty
        // if (empty($airlines)) {
        //     Log::warning("No airlines found for priority update", [
        //         'priority' => $this->priority,
        //         'service' => $this->service,
        //         'airline_code' => $this->airlineCode
        //     ]);
        //     return;
        // }

        // ✅ Get service-specific classes
        $serviceEnum = ServiceProviderEnum::from($this->service);
        $providerClass = $serviceEnum->getProvider();
        $updaterClass = $serviceEnum->getUpdater(); // ← جدید

        foreach ($airlines as $config) {
            try {
                // Validate config structure
                // if (!is_array($config) || !isset($config['code'])) {
                    // Log::error("Invalid config structure for priority {$this->priority}", [
                    //     'service' => $this->service,
                    //     'config' => $config
                    // ]);
                //     continue;
                // }

                // Log::info("Starting priority {$this->priority} update for {$config['code']}", [
                //     'service' => $this->service
                // ]);

                $provider = new $providerClass($config);

                // ✅ Use Updater instead of FlightUpdateService
                $updater = new $updaterClass($provider, $config['code'], $this->service);

                $stats = $updater->updateByPeriod($this->priority);

                // Log::info("Priority {$this->priority} update completed for {$config['code']}", $stats);
            } catch (\Exception $e) {
                $airlineCode = $config['code'] ?? 'unknown';
                // Log::error("Priority update failed for {$airlineCode}: " . $e->getMessage(), [
                //     'priority' => $this->priority,
                //     'service' => $this->service,
                //     'trace' => $e->getTraceAsString()
                // ]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Log::error("UpdateFlightsPriorityJob failed", [
        //     'priority' => $this->priority,
        //     'service' => $this->service,
        //     'airline' => $this->airlineCode,
        //     'error' => $exception->getMessage()
        // ]);
    }
}
