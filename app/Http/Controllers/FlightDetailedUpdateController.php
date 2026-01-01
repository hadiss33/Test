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
     * بروزرسانی و تکمیل اطلاعات دقیق پروازها (قیمت‌ها، بار، قوانین استرداد و تفکیک مالیات)
     * * GET/POST /api/flights/update-detailed
     * Body/Query: {
     * "service": "nira",
     * "airline": "NV" (اختیاری)
     * }
     */
    public function updateDetailed(Request $request)
    {
        // ۱. اعتبارسنجی ورودی‌ها
        $validator = Validator::make($request->all(), [
            'service' => 'required|string',
            'airline' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service = $request->input('service');
        $airlineCode = $request->input('airline');

        // ۲. دریافت تنظیمات رابط کاربری (Interface) برای ایرلاین‌های مورد نظر
        $airlines = $airlineCode
            ? [$this->repository->getServiceByCode($service, $airlineCode)]
            : $this->repository->getActiveServices($service);

        $airlines = array_filter($airlines);

        if (empty($airlines)) {
            return response()->json([
                'status' => 'error',
                'message' => 'هیچ ایرلاین فعالی برای این سرویس یافت نشد.'
            ], 404);
        }

        $allResults = [];
        $startTime = microtime(true);

        // ۳. پردازش هر ایرلاین به صورت مجزا
        foreach ($airlines as $config) {
            try {
                // ایجاد نمونه از پروایدر و سرویس مربوطه
                $provider = new NiraProvider($config);
                $detailedService = new FlightDetailedUpdateService(
                    $provider,
                    $config['code'], // IATA (e.g., NV)
                    $service         // e.g., nira
                );

                // فراخوانی متد جدید بازنویسی شده در سرویس
                $stats = $detailedService->updateFlightsDetails();

                $allResults[] = [
                    'airline' => $config['name'] ?? $config['code'],
                    'iata'    => $config['code'],
                    'status'  => 'success',
                    'stats'   => $stats
                ];

            } catch (\Exception $e) {
                Log::error("خطا در بروزرسانی جزئیات ایرلاین {$config['code']}: " . $e->getMessage());
                $allResults[] = [
                    'iata'    => $config['code'],
                    'status'  => 'error',
                    'message' => 'بروز خطا در پردازش: ' . $e->getMessage()
                ];
            }
        }

        $executionTime = round(microtime(true) - $startTime, 2);

        // ۴. بازگرداندن پاسخ نهایی با جزئیات کامل
        return response()->json([
            'status' => 'completed',
            'execution_time_seconds' => $executionTime,
            'summary' => [
                'total_airlines_processed' => count($allResults),
                'total_flights_checked'    => array_sum(array_column(array_column($allResults, 'stats'), 'flights_processed')),
                'total_classes_updated'    => array_sum(array_column(array_column($allResults, 'stats'), 'classes_updated')),
            ],
            'details' => $allResults
        ]);
    }
}