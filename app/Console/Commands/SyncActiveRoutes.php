<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use App\Services\RouteSyncService;
use App\Services\FlightProviders\NiraProvider;

class SyncActiveRoutes extends Command
{
    protected $signature = 'flights:sync-routes {service=nira} {iata?}';
    protected $description = 'Sync active routes';

    protected $repository;

    public function __construct(FlightServiceRepositoryInterface $repository)
    {
        parent::__construct();
        $this->repository = $repository;
    }

    public function handle()
    {
        $service = $this->argument('service');
        $iata = $this->argument('iata');

        $airlines = $iata
            ? [$this->repository->getServiceByCode($service, $iata)]
            : $this->repository->getActiveServices($service);

        $airlines = array_filter($airlines);

        foreach ($airlines as $airlineConfig) {
            $this->info("Syncing {$airlineConfig['name']} ({$airlineConfig['code']})...");
            
            $provider = new NiraProvider($airlineConfig);
            $syncService = new RouteSyncService($provider, $airlineConfig['code'], $service);
            
            $result = $syncService->sync();
            
            if ($result['success']) {
                $this->info("✓ Routes: {$result['routes_count']}");
            } else {
                $this->error("✗ {$result['message']}");
            }
        }

        return 0;
    }
}
//php artisan flights:sync-routes nira