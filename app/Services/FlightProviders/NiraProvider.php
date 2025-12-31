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
                    'cbChildQty' =>0,
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
                
                $responseBody = $response->getBody()->getContents();
                
                // Fix UTF-8 encoding issues
                $responseBody = $this->fixUtf8Encoding($responseBody);
                
                $data = json_decode($responseBody, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error("Nira Fare JSON Error [{$this->config['code']}]", [
                        'error' => json_last_error_msg(),
                        'route' => "{$origin}-{$destination}",
                        'class' => $flightClass
                    ]);
                    return null;
                }
                
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

    /**
     * Fix UTF-8 encoding issues in API response
     */
    protected function fixUtf8Encoding(string $text): string
    {
        // Try multiple encoding fixes
        
        // Method 1: Convert from Windows-1256 (Arabic/Persian encoding)
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1256');
            if ($converted !== false && $this->isValidJson($converted)) {
                return $converted;
            }
        }
        
        // Method 2: Convert from ISO-8859-1
        if (function_exists('utf8_encode')) {
            $converted = @utf8_encode($text);
            if ($this->isValidJson($converted)) {
                return $converted;
            }
        }
        
        // Method 3: Try iconv
        if (function_exists('iconv')) {
            $encodings = ['Windows-1256', 'ISO-8859-1', 'CP1256', 'UTF-16'];
            foreach ($encodings as $encoding) {
                $converted = @iconv($encoding, 'UTF-8//IGNORE', $text);
                if ($converted !== false && $this->isValidJson($converted)) {
                    return $converted;
                }
            }
        }
        
        // Method 4: Remove invalid UTF-8 characters
        if (function_exists('mb_check_encoding')) {
            if (!mb_check_encoding($text, 'UTF-8')) {
                // Remove invalid characters
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
        }
        
        // Last resort: Remove all non-ASCII and non-UTF8 characters from strings but keep numbers and structure
        $text = preg_replace_callback('/"([^"]+)"/', function($matches) {
            // Only clean the actual string values, not the structure
            $value = $matches[1];
            // If it's purely numeric or looks like a key, don't touch it
            if (is_numeric($value) || preg_match('/^[a-zA-Z_]+$/', $value)) {
                return $matches[0];
            }
            // Otherwise, remove invalid UTF-8
            return '"' . preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\xFF]/', '', $value) . '"';
        }, $text);
        
        return $text;
    }

    /**
     * Check if string is valid JSON
     */
    protected function isValidJson(string $text): bool
    {
        json_decode($text);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public function parseAvailableSeats(string $cap, string $flightClass): int
    {
        if (str_starts_with($cap, $flightClass)) {
            $cap = substr($cap, strlen($flightClass));
        }

        if ($cap === '') {
            return 0;
        }

        if ($cap === 'A') {
            return 9;
        }

        if (is_numeric($cap)) {
            return (int) $cap;
        }

        return 0;
    }

    public function determineStatus(string $capacity): string
    {
        $statusChar = substr($capacity, -1);

        if ($statusChar === 'X') {
            return 'cancelled';
        }

        if ($statusChar === 'C') {
            return 'closed';
        }

        if ($statusChar === '0') {
            return 'full';
        }

        if ($statusChar === 'A' || is_numeric($statusChar)) {
            return 'active';
        }

        return 'closed';
    }
}