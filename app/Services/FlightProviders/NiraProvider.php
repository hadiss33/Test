<?php

namespace App\Services\FlightProviders;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\{Log, Cache};

class NiraProvider implements FlightProviderInterface
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
        $url = $this->config['base_url_ws1'] . '/NRSCWS.jsp';
        
        try {
            $response = $this->client->get($url, [
                'query' => [
                    'ModuleType' => 'SP',
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
                    'cbChildQty' => 1,
                    'cbInfantQty' => 1,
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


    public function getFare(string $origin, string $destination, string $flightClass, string $date, string $flightNo = ''): ?array
    {
        $cacheKey = "fare:{$this->config['code']}:{$origin}-{$destination}:{$flightClass}:{$date}:{$flightNo}";
        
        return Cache::remember($cacheKey, 300, function() use ($origin, $destination, $flightClass, $date, $flightNo) {
            $url = $this->config['base_url_ws1'] . '/FareJS.jsp';
            
            try {
                $response = $this->client->get($url, [
                    'query' => [
                        'AirLine' => $this->config['code'],
                        'Route' => "{$origin}-{$destination}",
                        'RBD' => $flightClass,
                        'DepartureDate' => $date,
                        'FlightNO' => $flightNo,
                        'OfficeUser' => $this->config['office_user'],
                        'OfficePass' => $this->config['office_pass'],
                    ]
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                
                if (isset($data['Error']) || empty($data)) {
                    return null;
                }
                
                return $data;
                
            } catch (\Exception $e) {
                Log::error("Nira Fare Error [{$this->config['code']}]: " . $e->getMessage(), [
                    'route' => "{$origin}-{$destination}",
                    'class' => $flightClass,
                    'date' => $date
                ]);
                return null;
            }
        });
    }


    public function parseAvailableSeats(string $capacity): int
    {
        if ($capacity === 'A' || str_contains($capacity, 'A')) {
            return 9;
        }
        
        if (preg_match('/\d+/', $capacity, $matches)) {
            return (int) $matches[0];
        }
        
        return 0;
    }


    public function determineStatus(string $capacity): string
    {
        if (str_contains($capacity, 'X')) {
            return 'cancelled';
        }
        
        if (str_contains($capacity, 'C')) {
            return 'closed';
        }
        
        if ($capacity === '0' || preg_match('/^[A-Z]+0$/', $capacity)) {
            return 'full';
        }
        
        return 'active';
    }
}