<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use App\Services\FlightUpdateService;
use App\Services\FlightProviders\NiraProvider;

class UpdateFlights extends Command
{

    protected $signature = 'flights:update {service=nira} {--airline=} {--priority=}';

    protected $description = 'Update flights availability and pricing manually';

    protected $repository;

    public function __construct(FlightServiceRepositoryInterface $repository)
    {
        parent::__construct();
        $this->repository = $repository;
    }

    public function handle()
    {
        $serviceName = $this->argument('service');
        $airlineCode = $this->option('airline');
        $priorityInput = $this->option('priority');

        $airlines = $airlineCode
            ? [$this->repository->getServiceByCode($serviceName, $airlineCode)]
            : $this->repository->getActiveServices($serviceName);

        $airlines = array_filter($airlines);

        if (empty($airlines)) {
            $this->error("No active airlines found for service: {$serviceName}");
            return 1;
        }

        foreach ($airlines as $config) {
            $this->info("------------------------------------------------");
            $this->info("Fetching Flights for: {$config['name']} ({$config['code']})");

            try {
                $provider = new NiraProvider($config);
                $updateService = new FlightUpdateService($provider, $config['code'], $serviceName);

                $priorities = $priorityInput ? [$priorityInput] : [1, 2, 3, 4];

                foreach ($priorities as $priority) {
                    $this->line("   > Checking Priority {$priority}...");
                    
                    $stats = $updateService->updateByPriority((int)$priority);
                    
                    $this->info("     ✓ Processed: {$stats['checked']} flights");
                    
                    if ($stats['errors'] > 0) {
                        $this->warn("     ⚠ Errors: {$stats['errors']}");
                        $this->line("       (Check storage/logs/laravel.log for details)");
                    }
                }

            } catch (\Exception $e) {
                $this->error("Critical Error for {$config['code']}: " . $e->getMessage());
            }
        }

        $this->info("------------------------------------------------");
        $this->info("All operations completed.");
        return 0;
    }
}
//php artisan flights:update nira --airline=NV --priority=1