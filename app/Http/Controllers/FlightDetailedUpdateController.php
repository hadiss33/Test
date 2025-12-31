<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use App\Services\FlightDetailedUpdateService;
use App\Services\FlightProviders\NiraProvider;
use Illuminate\Support\Facades\{Log, Validator};

class FlightDetailedUpdateController extends Controller
{
    protected $repository;

    public function __construct(FlightServiceRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function updateDetailed(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'service' => 'required|string',
            'airline' => 'required|string',
            'priority' => 'required|integer|between:1,4'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service = $request->input('service');
        $airlineCode = $request->input('airline');
        $priority = $request->input('priority');

        $config = $this->repository->getServiceByCode($service, $airlineCode);

        if (!$config) {
            return response()->json([
                'status' => 'error',
                'message' => 'Airline not found or inactive'
            ], 404);
        }

        try {
            // ساخت provider و service
            $provider = new NiraProvider($config);
            $updateService = new FlightDetailedUpdateService($provider, $config['code'], $service);

            // به‌روزرسانی پروازها با تمام جزئیات
            $stats = $updateService->updateByPriority($priority);

            return response()->json([
                'status' => 'success',
                'airline' => $config['name'],
                'iata' => $config['code'],
                'priority' => $priority,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error("Detailed flight update error for {$config['code']}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'airline' => $config['name'],
                'iata' => $config['code'],
                'message' => $e->getMessage()
            ], 500);
        }
    }
}