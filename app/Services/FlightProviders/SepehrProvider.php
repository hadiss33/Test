<?php

namespace App\Services\FlightProviders;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use App\DTOs\RouteScheduleDTO; 

class SepehrProvider implements FlightProviderInterface
{
    protected $config;
    protected $client;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new Client([
            'timeout' => 130,
            'verify' => false,
        ]);
    }

    public function getFlightsSchedule(string $fromDate, string $toDate): array
    {
        $url = "https://partners.sepehrsupport.ir/flight/availability/v17/getactiveroutes";

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'X-API-KEY' => $this->config['office_pass'] ?? '', 
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $sepehrRoutes = $data['ActiveRouteList'] ?? []; // فرض بر اساس داکیومنت سپهر

            $dtos = [];

            foreach ($sepehrRoutes as $route) {
                // تبدیل مستقیم روزهای هفته به DTO (بدون نیاز به تحلیل تاریخ)
                $dtos[] = new RouteScheduleDTO(
                    origin: strtoupper($route['OriginIataCode']),
                    destination: strtoupper($route['DestinationIataCode']),
                    iata: $this->config['code'],
                    application_interfaces_id: $this->config['id'],
                    
                    // مپ کردن مستقیم فیلدهای سپهر به DTO
                    monday: $route['Monday'] ?? false,
                    tuesday: $route['Tuesday'] ?? false,
                    wednesday: $route['Wednesday'] ?? false,
                    thursday: $route['Thursday'] ?? false,
                    friday: $route['Friday'] ?? false,
                    saturday: $route['Saturday'] ?? false,
                    sunday: $route['Sunday'] ?? false
                );
            }

            return $dtos;

        } catch (\Exception $e) {
            Log::error("Sepehr Schedule Error: " . $e->getMessage());
            return [];
        }
    }

    // --- متدهای پوچ (Stub Methods) برای رعایت قوانین اینترفیس ---

    public function getAvailabilityFare(string $origin, string $destination, string $date): array
    {
        // فعلاً خالی (چون شاید هنوز برای قیمت‌گیری از سپهر آماده نیستید)
        return [];
    }

    public function getFare(string $origin, string $destination, string $flightClass, string $date, string $flightNo = ''): ?array
    {
        // برگشت null تا ارور ندهد
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
        return $key ? ($this->config[$key] ?? null) : $this->config;
    }

    public function prepareAvailabilityRequestData(string $origin, string $destination, string $date): array
    {
        return [];
    }
}