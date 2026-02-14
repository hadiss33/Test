<?php

namespace App\Services\FlightProviders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use Morilog\Jalali\Jalalian;


class RavisProvider implements FlightProviderInterface
{
    protected array $config;
    protected string $baseUrl;
    protected string $username;
    protected string $mainId;
    protected bool $verify;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->baseUrl = rtrim($config['url'], '/');
        $this->username = $config['username'];
        
        $data = $config['data'] ?? [];
        $this->mainId = $data['main_id'] ?? '';
        $this->verify = $data['verify'] ?? false;
    }

    public function getFlightsSchedule(string $fromDate, string $toDate): array
    {
        return $this->getRavisFlights($fromDate, $toDate);
    }

    public function getCharterFlights(string $fromDate, string $toDate): array
    {
        return $this->getRavisFlights($fromDate, $toDate);
    }


    protected function getRavisFlights(string $fromDate, string $toDate): array
    {
        try {
            $url = "{$this->baseUrl}/api/flights/ravisFlightList";
           $fromDate = Jalalian::fromDateTime($fromDate)->format('Ymd');
           $toDate = Jalalian::fromDateTime($toDate)->format('Ymd');

            Log::info("Calling Ravis API", [
                'url' => $url,
                'from' => $fromDate,
                'to' => $toDate,
                'timeout' => '120s',
            ]);

            $response = Http::withOptions([
                    'verify' => $this->verify,
                    'timeout' => 120, 
                    'connect_timeout' => 60, 
                ])
                ->retry(2, 5000)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Connection' => 'keep-alive',
                ])
                ->post($url, [
                    'CustomerId' => $this->username,
                    'Date1' => $fromDate,
                    'Date2' => $toDate,
                ]);

            if ($response->failed()) {
                Log::error("Ravis API Error", [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 1000), 
                ]);
                return [];
            }

            $flights = $response->json();

            if (!is_array($flights)) {
                Log::error("Ravis API returned non-array response", [
                    'response_type' => gettype($flights),
                    'response_preview' => is_string($flights) ? substr($flights, 0, 200) : json_encode($flights),
                ]);
                return [];
            }

            Log::info("Ravis API success", [
                'flights_count' => count($flights),
                'from' => $fromDate,
                'to' => $toDate,
                'response_time' => $response->handlerStats()['total_time'] ?? 'unknown',
            ]);

            return $flights;

        } catch (Exception $e) {
            Log::error("Ravis API Exception", [
                'url' => $url ?? 'unknown',
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'from' => $fromDate,
                'to' => $toDate,
            ]);
            return [];
        }
    }

    // ========== Methods from FlightProviderInterface ==========

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
        $seats = (int) $capacity;
        
        if ($seats <= 0) {
            return 'full';
        }
        
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