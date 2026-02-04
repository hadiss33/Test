<?php

namespace App\Services\FlightProviders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class SepehrProvider implements FlightProviderInterface
{
    protected array $config;

    protected string $baseUrl;

    protected string $username;

    protected string $password;



    public function __construct(array $config)

    {

        $this->config = $config;

        $this->baseUrl = rtrim($config['url'], '/');

        $this->username = $config['username'];

        $this->password = $config['password'];

    }

    public function getFlightsSchedule(string $fromDate, string $toDate): array
    {
        try {
            $url = "{$this->baseUrl}/api/Partners/Flight/Availability/V17/GetActiveRoutes";

            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'Username' => $this->username,
                    'Password' => md5($this->password),
                    'FetchSupplierWebserviceFlights' => true,
                ]);

            if ($response->failed()) {
                Log::error("Sepehr API Error: " . $response->body());
                return [
                    'status'  => false,
                    'message' => $response->json('ErrorMessage') ?? 'خطای ناشناخته از سمت سپهر',
                    'code'    => $response->json('ExceptionType') ?? 'UnknownError'
                ];
            }
            $data = $response->json();

           return   $data['ActiveRouteList'] ??[] ;

        } catch (Exception $e) {
            Log::error("Sepehr Connection Exception: " . $e->getMessage());
            return [
                'status'  => false,
                'message' => 'خطا در برقراری ارتباط با وب‌سرویس سپهر',
                'trace'   => $e->getMessage()
            ];
        }
    }


    public function getAvailabilityFare(string $origin, string $destination, string $date): array
    {
        return [];
    }

    public function getFare(string $origin, string $destination, string $flightClass, string $date, string $flightNo = ''): ?array
    {
        return null;
    }

    public function parseAvailableSeats(string $cap, string $flightClass): int
    {
        return (int) $cap;
    }

    public function determineStatus(string $capacity): string
    {
        return 'active';
    }

    public function getConfig(?string $key = null)
    {
        if ($key) {
            return $this->config[$key] ?? null;
        }
        return $this->config;
    }

    public function prepareAvailabilityRequestData(string $origin, string $destination, string $date): array
    {
        return [];
    }
}