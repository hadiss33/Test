<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use Illuminate\Support\Facades\Log;
use App\Services\FlightUpdateService;
use App\Services\FlightCleanupService;
use Illuminate\Support\Facades\Validator;
use App\Enums\ServiceProviderEnum;


class FlightUpdateController extends Controller
{
    protected $repository;

    public function __construct(FlightServiceRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function update(Request $request)
    {
        // Increase execution time for large updates
        set_time_limit(600); // 10 minutes
        ini_set('max_execution_time', 600);

        $validator = Validator::make($request->all(), [
            'service' => 'required|string',
            'airline' => 'nullable|string',
            'priority' => 'nullable|integer|between:1,4'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $service = $request->input('service');
        $airlineCode = $request->input('airline');
        $priorityInput = $request->input('period');

        $airlines = $airlineCode
            ? [$this->repository->getServiceByCode($service, $airlineCode)]
            : $this->repository->getActiveServices($service);

        $airlines = array_filter($airlines);

        if (empty($airlines)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active airlines found for the specified criteria'
            ], 404);
        }

        $priorities = $priorityInput ? [$priorityInput] : [3, 7,60 ,90, 120];

        $results = [];
        $startTime = now();

        $providerClass =ServiceProviderEnum::from($service)->getProvider();
        foreach ($airlines as $config) {
            $airlineResult = [
                'airline' => $config['name'],
                'iata' => $config['code'],
                'priorities' => [],
                'total_stats' => [
                    'checked' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'errors' => 0,
                    'routes_processed' => 0,
                    'flights_found' => 0
                ]
            ];

            try {
                $provider = new $providerClass($config);
                $updateService = new FlightUpdateService($provider, $config['code'], $service);

                foreach ($priorities as $priority) {
                    Log::info("Starting update for {$config['code']} - Priority {$priority}");
                    
                    $priorityStartTime = microtime(true);
                    $stats = $updateService->updateByPeriod($priority);
                    $priorityDuration = round(microtime(true) - $priorityStartTime, 2);

                    $airlineResult['priorities'][$priority] = array_merge($stats, [
                        'duration_seconds' => $priorityDuration
                    ]);

                    foreach ($stats as $key => $value) {
                        if (isset($airlineResult['total_stats'][$key])) {
                            $airlineResult['total_stats'][$key] += $value;
                        }
                    }

                    Log::info("Completed update for {$config['code']} - Priority {$priority}", $stats);
                }

                $airlineResult['status'] = 'success';

            } catch (\Exception $e) {
                Log::error("Flight update error for {$config['code']}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
                
                $airlineResult['status'] = 'error';
                $airlineResult['error_message'] = $e->getMessage();
            }

            $results[] = $airlineResult;
        }

        $totalDuration = now()->diffInSeconds($startTime);

        return response()->json([
            'status' => 'completed',
            'execution_time_seconds' => $totalDuration,
            'airlines_processed' => count($airlines),
            'priorities_processed' => $priorities,
            'results' => $results,
            'summary' => $this->calculateSummary($results)
        ]);
    }

    protected function calculateSummary(array $results): array
    {
        $summary = [
            'total_checked' => 0,
            'total_updated' => 0,
            'total_skipped' => 0,
            'total_errors' => 0,
            'total_routes' => 0,
            'total_flights' => 0,
            'success_airlines' => 0,
            'failed_airlines' => 0
        ];

        foreach ($results as $result) {
            if (isset($result['total_stats'])) {
                $summary['total_checked'] += $result['total_stats']['checked'] ?? 0;
                $summary['total_updated'] += $result['total_stats']['updated'] ?? 0;
                $summary['total_skipped'] += $result['total_stats']['skipped'] ?? 0;
                $summary['total_errors'] += $result['total_stats']['errors'] ?? 0;
                $summary['total_routes'] += $result['total_stats']['routes_processed'] ?? 0;
                $summary['total_flights'] += $result['total_stats']['flights_found'] ?? 0;
            }

            if (isset($result['status'])) {
                if ($result['status'] === 'success') {
                    $summary['success_airlines']++;
                } else {
                    $summary['failed_airlines']++;
                }
            }
        }

        return $summary;
    }

    public function cleanup(FlightCleanupService $cleanupService)
    {
        try {
            $result = $cleanupService->cleanupPastFlights();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Old flights cleaned up successfully',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('Cleanup error: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cleanup old flights',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function checkMissing(FlightCleanupService $cleanupService)
    {
        try {
            $result = $cleanupService->handleMissingFlights();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Missing flights check completed',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            Log::error('Check missing error: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check missing flights',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}