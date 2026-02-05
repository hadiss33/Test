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

    /**
     * Get active routes with weekday availability
     * Used for route synchronization
     */
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
                Log::error("Sepehr GetActiveRoutes Error: " . $response->body());
                return [];
            }

            $data = $response->json();
            return $data['ActiveRouteList'] ?? [];

        } catch (Exception $e) {
            Log::error("Sepehr GetActiveRoutes Exception: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get charter flights with complete data
     * Used for flight updates
     * 
     * Returns ALL flight data including:
     * - Flight details
     * - All classes with prices
     * - Baggage information
     * - Cancellation policies
     * 
     * @param string $fromDate Format: Y-m-d
     * @param string $toDate Format: Y-m-d
     * @return array
     */
    public function getCharterFlights(string $fromDate, string $toDate): array
    {
        try {
            $url = "{$this->baseUrl}/api/Partners/Flight/BulkAvailability/V17/GetCharterFlights";

            $response = Http::timeout(60) // Longer timeout for bulk data
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Accept-Encoding' => 'gzip, deflate',
                ])
                ->post($url, [
                    'UserName' => $this->username,
                    'Password' => md5($this->password),
                    'FromDate' => $fromDate,
                    'ToDate' => $toDate,
                    'FetchFlighsWithBookingPolicy' => false,
                    'Language' => 'FA',
                ]);

            if ($response->failed()) {
                Log::error("Sepehr GetCharterFlights Error", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $data = $response->json();

            Log::info("Sepehr GetCharterFlights success", [
                'flights_count' => count($data['CharterFlightList'] ?? []),
                'from' => $fromDate,
                'to' => $toDate,
            ]);

            return $data;

        } catch (Exception $e) {
            Log::error("Sepehr GetCharterFlights Exception", [
                'error' => $e->getMessage(),
                'from' => $fromDate,
                'to' => $toDate,
            ]);
            return [];
        }
    }

    // ========== Methods from FlightProviderInterface ==========

    public function getAvailabilityFare(string $origin, string $destination, string $date): array
    {
        // Sepehr doesn't use this method - uses GetCharterFlights instead
        return [];
    }

    public function getFare(string $origin, string $destination, string $flightClass, string $date, string $flightNo = ''): ?array
    {
        // Sepehr doesn't use this method - all data comes from GetCharterFlights
        return null;
    }

    public function parseAvailableSeats(string $cap, string $flightClass): int
    {
        // Sepehr provides direct number
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
        // Sepehr doesn't use per-route requests
        return [];
    }
}