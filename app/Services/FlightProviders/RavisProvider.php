<?php

namespace App\Services\FlightProviders;

class RavisProvider implements FlightProviderInterface
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

        return [];

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
     * @param  string  $fromDate  Format: Y-m-d
     * @param  string  $toDate  Format: Y-m-d
     */
    public function getCharterFlights(string $fromDate, string $toDate): array
    {

        return [];

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
