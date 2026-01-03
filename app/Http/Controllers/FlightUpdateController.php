<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use App\Jobs\UpdateFlightsPriorityJob;
use Illuminate\Support\Facades\Validator;

class FlightUpdateController extends Controller
{
    protected $repository;

    public function __construct(FlightServiceRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Dispatch update job to queue instead of running synchronously
     */
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

        // Get airlines to process
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

        // Dispatch jobs to queue
        $priorities = $priorityInput ? [$priorityInput] : [1, 2, 3, 4];
        $jobsDispatched = 0;

        foreach ($airlines as $config) {
            foreach ($priorities as $priority) {
                UpdateFlightsPriorityJob::dispatch($priority, $service, $config['code']);
                $jobsDispatched++;
            }
        }

        return response()->json([
            'status' => 'queued',
            'message' => 'Flight update jobs queued successfully',
            'jobs_dispatched' => $jobsDispatched,
            'airlines' => array_column($airlines, 'name'),
            'priorities' => $priorities
        ]);
    }

    /**
     * Synchronous update for testing (with chunking)
     */
    public function updateSync(Request $request)
    {
        ini_set('max_execution_time', 600); // 10 minutes
        
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
        $priorities = $priorityInput ? [$priorityInput] : [1];

        foreach ($airlines as $config) {
            try {
                $provider = new \App\Services\FlightProviders\NiraProvider($config);
                $updateService = new \App\Services\FlightUpdateService($provider, $config['code'], $service);

                $airlineStats = [
                    'airline' => $config['name'],
                    'iata' => $config['code'],
                    'priorities' => []
                ];

                foreach ($priorities as $priority) {
                    $stats = $updateService->updateByPriority($priority);
                    $airlineStats['priorities'][$priority] = $stats;
                }

                $results[] = $airlineStats;

            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Flight update error for {$config['code']}: " . $e->getMessage());
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

    public function cleanup(\App\Services\FlightCleanupService $cleanupService)
    {
        $result = $cleanupService->cleanupPastFlights();
        
        return response()->json([
            'status' => 'success',
            'data' => $result
        ]);
    }

    public function checkMissing(\App\Services\FlightCleanupService $cleanupService)
    {
        $result = $cleanupService->handleMissingFlights();
        
        return response()->json([
            'status' => 'success',
            'data' => $result
        ]);
    }
}