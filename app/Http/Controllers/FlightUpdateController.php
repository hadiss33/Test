<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use App\Services\RouteSyncService;
use App\Services\FlightProviders\NiraProvider;
use Illuminate\Support\Facades\Log;
use App\Services\FlightUpdateService;
use App\Services\FlightCleanupService;
use Illuminate\Support\Facades\Validator;

class FlightUpdateController extends Controller
{
    protected $repository;

    public function __construct(FlightServiceRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'service' => 'required|string',
            'airline' => 'nullable|string',
            'priority' => 'nullable|integer|between:1,4'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service = $request->input('service');
        $airlineCode = $request->input('airline');
        $priorityInput = $request->input('priority');

        $airlines = $airlineCode
            ? [$this->repository->getServiceByCode($service, $airlineCode)]
            : $this->repository->getActiveServices($service);

        $airlines = array_filter($airlines);

        if (empty($airlines)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active airlines found'
            ], 404);
        }

        $results = [];
        $priorities = $priorityInput ? [$priorityInput] : [1, 2, 3, 4];

        foreach ($airlines as $config) {
            try {
                $provider = new NiraProvider($config);
                $updateService = new FlightUpdateService($provider, $config['code'], $service);

                $airlineStats = ['airline' => $config['name'], 'iata' => $config['code'], 'priorities' => []];

                foreach ($priorities as $priority) {
                    $stats = $updateService->updateByPriority($priority);
                    $airlineStats['priorities'][$priority] = $stats;
                }

                $results[] = $airlineStats;

            } catch (\Exception $e) {
                Log::error("Flight update error for {$config['code']}: " . $e->getMessage());
                $results[] = [
                    'airline' => $config['name'],
                    'iata' => $config['code'],
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'status' => 'completed',
            'results' => $results
        ]);
    }


    public function cleanup(FlightCleanupService $cleanupService)
    {
        $result = $cleanupService->cleanupPastFlights();
        
        return response()->json([
            'status' => 'success',
            'data' => $result
        ]);
    }

    public function checkMissing(FlightCleanupService $cleanupService)
    {
        $result = $cleanupService->handleMissingFlights();
        
        return response()->json([
            'status' => 'success',
            'data' => $result
        ]);
    }
}
