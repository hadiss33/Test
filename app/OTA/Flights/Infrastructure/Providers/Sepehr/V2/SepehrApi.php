<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2;

use App\Services\OTA\Flights\Application\DTOs\V2\Request;
use App\Services\OTA\Flights\Domain\Entities\V2\FlightCollection;
use Illuminate\Support\Facades\DB;

final class SepehrApi
{


    public static function lock(array $data, bool $details = false, mixed $suppliers = false): array
    {
        $supplierId = is_array($suppliers) ? ($suppliers[0] ?? null) : $suppliers;
        
        if (!$supplierId) {
            return [
                'Data' => [[
                    'Status' => false,
                    'Code' => '2007',
                    'Message' => ['Information' => 'Api Not Found!']
                ]]
            ];
        }

        $tempApi = DB::table('application_interface')
            ->where('object_type', 'colleague')
            ->where('object', $supplierId)
            ->where('status', 1)
            ->where('type', 'api')
            ->where('service', 'sepehr')
            ->first();

        if (!$tempApi) {
            return [
                'Data' => [[
                    'Status' => false,
                    'Code' => '2007',
                    'Message' => ['Information' => 'Api Not Found!']
                ]]
            ];
        }

        $client = new Client($tempApi->url, $tempApi->username, $tempApi->password);
        
        $param = [
            'Username' => $tempApi->username,
            'Password' => md5($tempApi->password),
        ];

        // Map old format to V17 format
        $payload = [
            'DepartureSegment' => $data['DepartureSegment'] ?? null,
            'ReturningSegment' => $data['ReturningSegment'] ?? null,
            'AdultCount' => $data['AdultCount'] ?? 0,
            'ChildCount' => $data['ChildCount'] ?? 0,
            'InfantCount' => $data['InfantCount'] ?? 0,
            'TotalPayable' => $data['TotalPayable'] ?? 0,
        ];

        try {
            $response = $client->post(Config::getEndpoint('Lock'), array_merge($param, $payload));
            
            if (isset($response['ErrorMessage'])) {
                return [
                    'Data' => [[
                        'Status' => true,
                        'Result' => [
                            'ErrorMessage' => $response['ErrorMessage'],
                            'ExceptionType' => $response['ExceptionType'] ?? 'Exception',
                            'TraceId' => $response['TraceId'] ?? null,
                        ]
                    ]]
                ];
            }

            return [
                'Data' => [[
                    'Status' => true,
                    'Result' => [
                        'DepartureSegmentLockId' => $response['DepartureSegmentLockId'] ?? null,
                        'ReturningSegmentLockId' => $response['ReturningSegmentLockId'] ?? null,
                        'ExpiryInMinute' => $response['ExpiryInMinute'] ?? 12,
                    ]
                ]]
            ];

        } catch (\Throwable $e) {
            return [
                'Data' => [[
                    'Status' => false,
                    'Code' => '2002-' . $e->getCode(),
                    'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                    'Trace' => $e->getTrace()
                ]]
            ];
        }
    }

    // ==================== BOOK ====================

    public static function book(array $data, bool $details = false, mixed $suppliers = false): array
    {
        $supplierId = is_array($suppliers) ? ($suppliers[0] ?? null) : $suppliers;
        
        if (!$supplierId) {
            return [
                'Data' => [[
                    'Status' => false,
                    'Code' => '2007',
                    'Message' => ['Information' => 'Api Not Found!']
                ]]
            ];
        }

        $tempApi = DB::table('application_interface')
            ->where('object_type', 'colleague')
            ->where('object', $supplierId)
            ->where('status', 1)
            ->where('type', 'api')
            ->where('service', 'sepehr')
            ->first();

        if (!$tempApi) {
            return [
                'Data' => [[
                    'Status' => false,
                    'Code' => '2007',
                    'Message' => ['Information' => 'Api Not Found!']
                ]]
            ];
        }

        $client = new Client($tempApi->url, $tempApi->username, $tempApi->password);

        $param = [
            'Username' => $tempApi->username,
            'Password' => md5($tempApi->password),
        ];

        foreach ($data['Data'] as $key => $value) {
            $param[$key] = $value;
        }

        try {
            $response = $client->post(Config::getEndpoint('Book'), $param);

            if (isset($data['SubData'])) {
                // TemporaryReservation dispatch (اگه نیازه)
            }

            if (!isset($response['LocalPnr']) && isset($response['ErrorMessage'])) {
                return [
                    'Data' => [[
                        'Status' => false,
                        'Code' => '1502-' . ($response['ExceptionType'] ?? 'Unknown'),
                        'Message' => $response['ErrorMessage']
                    ]]
                ];
            }

            return self::mapBookResponse($response, $data['SubData'] ?? null);

        } catch (\Throwable $e) {
            // Fallback to BookGetStatus
            try {
                $statusResponse = $client->postWithCredential(Config::getEndpoint('BookGetStatus'), [
                    'YourLocalInventoryPnr' => $param['YourLocalInventoryPnr'] ?? ''
                ]);

                if (isset($statusResponse['StatusId']) && $statusResponse['StatusId'] != 1) {
                    return [
                        'Data' => [[
                            'Status' => false,
                            'Code' => '1505-' . $statusResponse['StatusId'],
                            'Message' => ($param['YourLocalInventoryPnr'] ?? '') . ':' . ($statusResponse['StatusDesc'] ?? '') . ':Last Error:' . $e->getMessage()
                        ]]
                    ];
                }

                if (!isset($statusResponse['LocalPnr']) && isset($statusResponse['ErrorMessage'])) {
                    return [
                        'Data' => [[
                            'Status' => false,
                            'Code' => '1504-' . ($statusResponse['ExceptionType'] ?? 'Unknown'),
                            'Message' => $statusResponse['ErrorMessage']
                        ]]
                    ];
                }

                return [
                    'Data' => [[
                        'Status' => false,
                        'Code' => '2002-' . $e->getCode(),
                        'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.'
                    ]],
                    'Trace' => $e->getTrace()
                ];

            } catch (\Throwable $e2) {
                return [
                    'Data' => [[
                        'Status' => false,
                        'Code' => '2002-' . $e->getCode(),
                        'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.'
                    ]],
                    'Trace' => $e->getTrace()
                ];
            }
        }
    }

    // ==================== BOOK GET STATUS ====================

    public static function bookGetStatus(array $data, mixed $suppliers = false): array
    {
        $supplierId = is_array($suppliers) ? ($suppliers[0] ?? null) : $suppliers;
        
        if (!$supplierId) {
            return [
                'Data' => [[
                    'Status' => false,
                    'Code' => '2007',
                    'Message' => ['Information' => 'Api Not Found!']
                ]]
            ];
        }

        $tempApi = DB::table('application_interface')
            ->where('object_type', 'colleague')
            ->where('object', $supplierId)
            ->where('status', 1)
            ->where('type', 'api')
            ->where('service', 'sepehr')
            ->first();

        if (!$tempApi) {
            return [
                'Data' => [[
                    'Status' => false,
                    'Code' => '2007',
                    'Message' => ['Information' => 'Api Not Found!']
                ]]
            ];
        }

        $client = new Client($tempApi->url, $tempApi->username, $tempApi->password);

        $param = [
            'Credential' => [
                'Username' => $tempApi->username,
                'Password' => md5($tempApi->password),
            ],
            'YourLocalInventoryPnr' => $data['YourLocalInventoryPnr'] ?? '',
        ];

        try {
            return $client->postWithCredential(Config::getEndpoint('BookGetStatus'), $param);
        } catch (\Throwable $e) {
            return [
                'Data' => [[
                    'Status' => false,
                    'Code' => '2002-' . $e->getCode(),
                    'Message' => $e->getMessage() . ' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.'
                ]],
                'Trace' => $e->getTrace()
            ];
        }
    }

    // ==================== PRIVATE HELPERS ====================

    private static function mapBookResponse(array $response, ?array $subData): array
    {
        if (!isset($response['LocalPnr'])) {
            return [
                'Data' => [[
                    'Status' => false,
                    'Code' => '1501',
                    'Message' => $response
                ]]
            ];
        }

        $result = [];
        foreach ($response['PassengerList'] as $item) {
            $result[] = [
                'Status' => true,
                'DepartureSegment' => [
                    'PNR' => [
                        'Service' => $response['LocalPnr'],
                        'Original' => $item['DepartureSegment']['OriginalPnr'] ?? ''
                    ],
                    'Origin' => [
                        'Iata' => $item['DepartureSegment']['OriginIataCode'] ?? '',
                        'Terminal' => $subData['origin']['terminal'] ?? false
                    ],
                    'Destination' => [
                        'Iata' => $item['DepartureSegment']['DestinationIataCode'] ?? '',
                        'Terminal' => $subData['destination']['terminal'] ?? false
                    ],
                    'FlightDateTime' => $subData['departureDateTime'] ?? '',
                    'LocalTicketNumber' => $item['DepartureSegment']['LocalTicketNumber'] ?? '',
                    'OriginalTicketNumber' => $item['DepartureSegment']['OriginalTicketNumber'] ?? '',
                    'FlightNumber' => $item['DepartureSegment']['FlightNumber'] ?? '',
                    'Description' => $subData['description'] ?? ''
                ],
                'ReturningSegment' => false,
            ];
        }

        return ['Data' => $result, 'Result' => $response];
    }
    /**
     * Backward compatible wrapper for old code
     */
    public static function sendRequestFlight(
        string $requestType,
        array $data = [],
        bool $details = false,
        string $method = 'POST',
        mixed $suppliers = false,
        string|int|false $branch = false
    ): array {
        if ($requestType !== 'SearchByRouteAndDate') {
            throw new \InvalidArgumentException("Unsupported request type: {$requestType}");
        }

        return self::searchByRouteAndDate($data['Data'] ?? [], $details, $branch);
    }

    public static function searchByRouteAndDate(array $data, bool $details = false, string|int|false $branch = false): array
    {
        $request = new Request(
            originIataCode: $data['OriginIataCode'] ?? '',
            destinationIataCode: $data['DestinationIataCode'] ?? '',
            departureDate: $data['DepartureDate'] ?? '',
            returningDate: $data['ReturningDate'] ?? null,
            fetchSupplierWebserviceFlights: $data['FetchSupplierWebserviceFlights'] ?? false,
            fetchFlightsWithBookingPolicy: $data['FetchFlighsWithBookingPolicy'] ?? true,
            language: $data['Language'] ?? 'FA',
            details: $details,
            branch: $branch,
        );

        $suppliers = Auth::getSuppliers($branch);
        $allFlights = new FlightCollection();
        $rawResults = [];
        
        foreach ($suppliers as $supplier) {
            try {
                if (!self::hasActiveRoute($supplier, $request)) {
                    continue;
                }

                $client = new Client($supplier['url'], $supplier['username'], $supplier['password']);
                $payload = $request->toSepehrPayload();
                if ($supplier['nira']) {
                    $payload['FetchSupplierWebserviceFlights'] = true;
                }

                $response = $client->post(Config::getEndpoint('SearchByRouteAndDate'), $payload);
                $rawResults[] = [
                    'status' => !isset($response['ErrorMessage']),
                    'supplier' => $supplier['object'],
                    'data' => $response,
                ];

                if (isset($response['ErrorMessage'])) {
                    continue;
                }

                $apiMapper = new ApiMapper(details: $details, branch: $branch);
                $flights = $apiMapper->mapSearchResponse($response, $supplier);
                
                foreach ($flights as $flight) {
                    $allFlights->add($flight);
                }

            } catch (\Throwable $e) {
                $rawResults[] = [
                    'status' => false,
                    'supplier' => $supplier['object'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return self::toLegacyFormat($allFlights, $rawResults, $details, $branch);
    }

    private static function hasActiveRoute(array $supplier, Request $request): bool
    {
        $origin = DB::table('airports')->select('id')->where('iata', $request->originIataCode)->first();
        $destination = DB::table('airports')->select('id')->where('iata', $request->destinationIataCode)->first();

        if (!$origin || !$destination) {
            return false;
        }

        $keyDay = strtolower(\Carbon\Carbon::parse($request->departureDate)->englishDayOfWeek);

        $route = DB::table('flight_active_route')
            ->where('colleague', $supplier['object'])
            ->where('origin', $origin->id)
            ->where('destination', $destination->id)
            ->where($keyDay, true)
            ->first();

        return !is_null($route);
    }

    private static function toLegacyFormat(FlightCollection $flights, array $rawResults, bool $details, string|int|false $branch): array
    {
        $legacyMapper = new LegacyMapper(details: $details, branch: $branch);
        $information = [];

        foreach ($flights as $flight) {
            $information[] = $legacyMapper->map($flight);
        }

        return [
            'Data' => [
                'Status' => !empty($information),
                'Time' => time(),
                'CurrencyCode' => 'IRR',
                'Information' => $information,
            ],
            'Result' => $rawResults,
        ];
    }
}