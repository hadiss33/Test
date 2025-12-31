<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use App\Services\FlightProviders\NiraProvider;
use App\Services\FlightDetailedUpdateService;
use Illuminate\Support\Facades\{Log, Validator};

class FlightDetailedUpdateController extends Controller
{
    protected $repository;

    public function __construct(FlightServiceRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * تکمیل اطلاعات ناقص پروازها
     * 
     * POST /api/flights/update-detailed
     * Body: {
     *   "service": "nira",
     *   "airline": "NV" (optional)
     * }
     */
    public function updateDetailed(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'service' => 'required|string',
            'airline' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service = $request->input('service');
        $airlineCode = $request->input('airline');

        // گرفتن لیست ایرلاین‌ها
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

        // ⏱️ شروع زمان‌سنجی
        $startTime = microtime(true);

        foreach ($airlines as $config) {
            try {
                $provider = new NiraProvider($config);
                $detailedService = new FlightDetailedUpdateService(
                    $provider,
                    $config['code'],
                    $service
                );

                $stats = $detailedService->fillMissingData();

                $results[] = [
                    'airline' => $config['name'],
                    'iata' => $config['code'],
                    'status' => 'completed',
                    'stats' => $stats
                ];

            } catch (\Exception $e) {
                Log::error("Detailed update error for {$config['code']}: " . $e->getMessage());
                $results[] = [
                    'airline' => $config['name'],
                    'iata' => $config['code'],
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        $executionTime = round(microtime(true) - $startTime, 2);

        return response()->json([
            'status' => 'completed',
            'execution_time_seconds' => $executionTime,
            'results' => $results
        ]);
    }
}