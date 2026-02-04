<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use App\Services\RouteSyncService;
use Illuminate\Support\Facades\Log;
use App\Enums\ServiceProviderEnum;

class RouteSyncController extends Controller
{
    protected $repository;

    public function __construct(FlightServiceRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function sync(Request $request)
    {
        $service = $request->input('service', 'nira'); 
        $iata = $request->input('iata'); 

        $providerClass  = ServiceProviderEnum::from($service)->getProvider();
        $airlines = $iata
            ? [$this->repository->getServiceByCode($service, $iata)]
            : $this->repository->getActiveServices($service);

        $airlines = array_filter($airlines);

        if (empty($airlines)) {
            return response()->json([
                'status' => 'error',
                'message' => 'هیچ سرویس فعال یا ایرلاین مشخصی یافت نشد.'
            ], 404);
        }

        $results = [];

        foreach ($airlines as $airlineConfig) {
            try {
                $provider = new $providerClass($airlineConfig);

                $syncService = new RouteSyncService($provider, $airlineConfig['code'], $service);

                $syncResult = $syncService->sync();

                $results[] = [
                    'airline' => $airlineConfig['name'],
                    'iata' => $airlineConfig['code'],
                    'status' => $syncResult['success'] ? 'success' : 'failed',
                    'routes_count' => $syncResult['routes_count'] ?? 0,
                    'message' => $syncResult['message'] ?? '',
                ];

            } catch (\Exception $e) {
                Log::error("Route Sync API Error [{$airlineConfig['code']}]: " . $e->getMessage());
                $results[] = [
                    'airline' => $airlineConfig['name'],
                    'iata' => $airlineConfig['code'],
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'status' => 'completed',
            'details' => $results
        ]);
    }
}