<?php

namespace App\Services\FlightProviders;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class NiraProvider implements FlightProviderInterface
{
    protected $config;
    protected $client;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new Client([
            'timeout' => 30,
            'verify' => false,
        ]);
    }

    public function getFlightsSchedule(string $fromDate, string $toDate): array
    {
        $url = $this->config['base_url_ws1'] . '/NRSCWS.jsp';
        
        try {
            $response = $this->client->get($url, [
                'query' => [
                    'ModuleType' => $this->config['ModuleType'],
                    'ModuleName' => 'Flights',
                    'FromDate' => $fromDate,
                    'ToDate' => $toDate,
                    'OfficeUser' => $this->config['office_user'],
                    'OfficePass' => $this->config['office_pass'],
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['NRSFlights'] ?? [];
        } catch (\Exception $e) {
            Log::error("Nira Schedule Error [{$this->config['code']}]: " . $e->getMessage());
            return [];
        }
    }

    public function getAvailabilityFare(string $origin, string $destination, string $date): array
    {
        $url = $this->config['base_url_ws1'] . '/AvailabilityFareJS.jsp';
        
        try {
            $response = $this->client->get($url, [
                'query' => [
                    'AirLine' => $this->config['code'],
                    'cbSource' => $origin,
                    'cbTarget' => $destination,
                    'DepartureDate' => $date,
                    'cbAdultQty' => 1,
                    'cbChildQty' => 0,
                    'cbInfantQty' => 0,
                    'OfficeUser' => $this->config['office_user'],
                    'OfficePass' => $this->config['office_pass'],
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['AvailableFlights'] ?? [];
        } catch (\Exception $e) {
            Log::error("Nira Availability Error [{$this->config['code']}]: " . $e->getMessage());
            return [];
        }
    }

    public function parseAvailableSeats(string $capacity): int
    {
        if ($capacity === 'A') return 9;
        if (is_numeric($capacity)) return (int) $capacity;
        return 0;
    }

    public function determineStatus(string $capacity): string
    {
        if (in_array($capacity, ['X', 'C'])) return 'closed';
        if ($capacity === '0') return 'full';
        return 'active';
    }
}
