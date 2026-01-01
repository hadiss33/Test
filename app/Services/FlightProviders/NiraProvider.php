<?php

namespace App\Services\FlightProviders;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
        $url = $this->config['base_url_ws1'].'/NRSCWS.jsp';

        try {
            $response = $this->client->get($url, [
                'query' => [
                    'ModuleType' => 'SP',
                    'ModuleName' => 'Flights',
                    'FromDate' => $fromDate,
                    'ToDate' => $toDate,
                    'OfficeUser' => $this->config['office_user'],
                    'OfficePass' => $this->config['office_pass'],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['NRSFlights'] ?? [];

        } catch (\Exception $e) {
            Log::error("Nira Schedule Error [{$this->config['code']}]: ".$e->getMessage());

            return [];
        }
    }

    public function getAvailabilityFare(string $origin, string $destination, string $date): array
    {
        $url = $this->config['base_url_ws1'].'/AvailabilityFareJS.jsp';

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
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['AvailableFlights'] ?? [];

        } catch (\Exception $e) {
            Log::error("Nira Availability Error [{$this->config['code']}]: ".$e->getMessage());

            return [];
        }
    }

    public function getFare(string $origin, string $destination, string $flightClass, string $date, string $flightNo = ''): ?array
    {
        $cacheKey = "fare:{$this->config['code']}:{$origin}-{$destination}:{$flightClass}:{$date}:{$flightNo}";

        return Cache::remember($cacheKey, 300, function () use ($origin, $destination, $flightClass, $date) {
            $url = $this->config['base_url_ws1'].'/FareJS.jsp';

            try {
                $response = $this->client->get($url, [
                    'query' => [
                        'AirLine' => '*',
                        'Route' => 'IFN-AWZ',
                        'RBD' => 'M',
                        'DepartureDate' => $date,
                        'FlightNO' => '2622',
                        'OfficeUser' => $this->config['office_user'],
                        'OfficePass' => $this->config['office_pass'],
                    ],
                ]);

                $rawBody = $response->getBody()->getContents();
                $utf8Body = iconv('Windows-1256', 'UTF-8//IGNORE', $rawBody);
                $rawData = json_decode($utf8Body, true);

                if (isset($rawData['Error']) || empty($rawData)) {
                    return null;
                }

                $crcnRules = [];
                if (! empty($rawData['CRCNRules'])) {
                    foreach (explode('/', trim($rawData['CRCNRules'], '/')) as $rule) {
                        $parts = explode(',', $rule);
                        $crcnRules[] = [
                            'text' => $parts[0] ?? '',
                            'percent' => isset($parts[1]) ? (int) $parts[1] : 0,
                        ];
                    }
                }

                $formatTaxes = function ($taxString) {
                    if (empty($taxString)) {
                        return [];
                    }
                    $result = [];
                    foreach (explode('$', trim($taxString, '$')) as $taxGroup) {
                        $taxItem = [];
                        foreach (explode(',', $taxGroup) as $part) {
                            if (str_contains($part, 'EN_Desc:')) {
                                $taxItem['title_en'] = substr($part, 8);
                            } elseif (str_contains($part, 'FA_Desc:')) {
                                $taxItem['title_fa'] = substr($part, 8);
                            } elseif (str_contains($part, ':')) {
                                [$key, $val] = explode(':', $part);
                                $taxItem["{$key}"] = $val;
                            }
                        }
                        $result[] = $taxItem;
                    }

                    return $result;
                };

                $data = [
                    'AdultTotalPrice' => $rawData['AdultTotalPrice'],
                    'InfantTotalPrice' => $rawData['InfantTotalPrice'],
                    'ChildTotalPrice' => $rawData['ChildTotalPrice'],
                    'AdultFare' => $rawData['AdultFare'],
                    'ChildFare' => $rawData['ChildFare'],
                    'InfantFare' => $rawData['InfantFare'],
                    'BaggageAllowanceWeight' => $rawData['BaggageAllowanceWeight'],
                    'BaggageAllowancePieces' => $rawData['BaggageAllowancePieces'],
                    'EligibilityText' => $rawData['EligibilityText'],
                    'CRCNRules' => $crcnRules,
                    'Taxes' => [
                        'Adult' => $formatTaxes($rawData['AdultTaxes'] ?? ''),
                        'Child' => $formatTaxes($rawData['ChildTaxes'] ?? ''),
                        'Infant' => $formatTaxes($rawData['InfantTaxes'] ?? ''),
                    ],
                ];

                if (isset($data['Error']) || empty($data)) {
                    return null;
                }

                return $data;

            } catch (\Exception $e) {
                Log::error("Nira Fare Error [{$this->config['code']}]: ".$e->getMessage(), [
                    'route' => "{$origin}-{$destination}",
                    'class' => $flightClass,
                    'date' => $date,
                ]);

                return null;
            }
        });
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
