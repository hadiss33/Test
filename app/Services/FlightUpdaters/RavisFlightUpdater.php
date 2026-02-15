<?php

namespace App\Services\FlightUpdaters;

use App\Models\AirlineActiveRoute;
use App\Models\Flight;
use App\Models\FlightBaggage;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Models\FlightFareBreakdown;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ravis Flight Updater
 *
 * Uses 1-step process:
 * - ravisFlightList - Returns complete flight data including:
 *   - Flight details (number, times, aircraft)
 *   - Class with fares (Adult, Child, Infant)
 *   - Baggage info
 *
 * NO Jobs needed - all data comes in one API call!
 */
class RavisFlightUpdater implements FlightUpdaterInterface
{
    protected FlightProviderInterface $provider;
    protected ?string $iata;
    protected string $service;

    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service = 'ravis')
    {
        $this->provider = $provider;
        $this->iata = $iata;
        $this->service = $service;
    }

    public function updateByPeriod(int $period): array
    {
        $stats = [
            'checked' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'routes_processed' => 0,
            'flights_found' => 0,
            'jobs_dispatched' => 0,  
        ];

        $fullConfig = $this->provider->getConfig();

        $query = AirlineActiveRoute::where('application_interfaces_id', $fullConfig['id'] ?? null);

        if ($this->iata) {
            $query->where('iata', $this->iata);
        }

        $routes = $query->get();

        if ($routes->isEmpty()) {
            Log::warning('No routes found for Ravis service', [
                'iata' => $this->iata,
            ]);
            return $stats;
        }

        $stats['routes_processed'] = $routes->count();

        [$startDate, $endDate] = $this->getDateRangeForPeriod($period);

        try {
            $flightsList = $this->provider->getCharterFlights($startDate, $endDate);

            if (empty($flightsList)) {
                Log::warning('Ravis ravisFlightList returned no flights', [
                    'period' => $period,
                    'from' => $startDate,
                    'to' => $endDate,
                    'iata' => $this->iata,
                ]);
                return $stats;
            }

            $stats['flights_found'] = count($flightsList);

            if ($this->iata) {
                $flightsList = array_filter($flightsList, function($flight) {
                    return ($flight['AirlineCode'] ?? '') === $this->iata;
                });
            }

            foreach ($flightsList as $flightData) {
                $this->processFlightData($flightData, $routes, $stats);
            }

            Log::info("Ravis update completed - Period {$period}", $stats);

        } catch (\Exception $e) {
            $stats['errors']++;
            Log::error('Ravis updateByPeriod failed', [
                'period' => $period,
                'iata' => $this->iata,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $stats;
    }

    protected function processFlightData(array $flightData, $routes, array &$stats): void
    {
        DB::beginTransaction();

        try {
            if (empty($flightData['Reservable'])) {
                DB::rollBack();
                return;
            }

            $origin = $flightData['IataCodSource'];
            $destination = $flightData['IataCodDestinate'];
            $airlineCode = $flightData['AirlineCode'];

            $route = $routes->first(function ($r) use ($origin, $destination, $airlineCode) {
                return $r->origin === $origin 
                    && $r->destination === $destination; 
            });

            if (!$route) {
                Log::debug('Ravis flight route not in active routes', [
                    'origin' => $origin,
                    'destination' => $destination,
                    'airline' => $airlineCode,
                    'flight_no' => $flightData['FlightNo'] ?? 'unknown',
                ]);
                DB::rollBack();
                return;
            }

            $departureDateTime = Carbon::parse($flightData['FlightDateTime']);

            $flight = Flight::updateOrCreate(
                [
                    'airline_active_route_id' => $route->id,
                    'flight_number' => $flightData['FlightNo'],
                    'departure_datetime' => $departureDateTime,
                    'iata'=> $flightData['AirlineCode']
                ],
                [
                    'updated_at' => now(),
                    'missing_count' => 0,
                ]
            );

            $isNewFlight = $flight->wasRecentlyCreated;

            $arrivalDateTime = $this->calculateArrivalTime(
                $departureDateTime,
                $flightData['ArrivalTime'] ?? null
            );

            FlightDetail::updateOrCreate(
                ['flight_id' => $flight->id],
                [
                    'arrival_datetime' => $arrivalDateTime,
                    'aircraft_code' => $flightData['AirPlaneName'] ?? null,
                    'aircraft_type_code' => $flightData['AirPlaneName'] ?? null,
                    'updated_at' => now(),
                ]
            );

            $hasChanges = $this->saveCompleteFlightClass($flight, $flightData);

            DB::commit();

            $stats['checked']++;
            if ($isNewFlight || $hasChanges) {
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }

        } catch (\Exception $e) {
            DB::rollBack();
            $stats['errors']++;

            Log::error('Ravis processFlightData error', [
                'flight_no' => $flightData['FlightNo'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }


    protected function saveCompleteFlightClass(Flight $flight, array $flightData): bool
    {
        $classCode = $flightData['Class'] ?? 'Y';
        $availableSeats = $flightData['CapLast'] ?? 0;

        $status = 'active';
        if ($availableSeats <= 0) {
            $status = 'full';
        }
        if ($flightData['Reservable'] === 0) {
            $status = 'closed';
        }

        $adultTotal = (float) ($flightData['PriceView'] ?? 0);
        $childTotal = (float) ($flightData['PriceCHD'] ?? $adultTotal);
        $infantTotal = (float) ($flightData['PriceINF'] ?? 0);

        $flightClass = FlightClass::updateOrCreate(
            [
                'flight_id' => $flight->id,
                'class_code' => $classCode,
            ],
            [
                'payable_adult' => $adultTotal,
                'payable_child' => $childTotal,
                'payable_infant' => $infantTotal,
                'available_seats' => $availableSeats,
                'status' => $status,
                'updated_at' => now(),
            ]
        );

        $wasChanged = $flightClass->wasRecentlyCreated || $flightClass->wasChanged();

        $this->saveFareBreakdown($flightClass, $flightData);

        $this->saveBaggage($flightClass, $flightData);

        return $wasChanged;
    }

    protected function saveFareBreakdown(FlightClass $flightClass, array $flightData): void
    {
        $adultSrv = (float) ($flightData['SrvPriceFinal'] ?? 0);
        $childSrv = (float) ($flightData['SrvPriceFinalCHD'] ?? 0);
        
        $adultBase = (float) ($flightData['PriceView'] ?? 0) - $adultSrv;
        $childBase = (float) ($flightData['PriceCHD'] ?? 0) - $childSrv;
        $infantBase = (float) ($flightData['PriceINF'] ?? 0);

        FlightFareBreakdown::updateOrCreate(
            ['flight_class_id' => $flightClass->id],
            [
                'base_adult' => $adultBase,
                'base_child' => $childBase,
                'base_infant' => $infantBase,
                'updated_at' => now(),
            ]
        );
    }

    protected function saveBaggage(FlightClass $flightClass, array $flightData): void
    {
        $baggageWeight = $this->parseBaggageWeight($flightData['FreeBag'] ?? '0');

        FlightBaggage::updateOrCreate(
            ['flight_class_id' => $flightClass->id],
            [
                'adult_weight' => $baggageWeight,
                'adult_pieces' => 1,
                'child_weight' => 0,
                'child_pieces' => 0,
                'infant_weight' => 0,
                'infant_pieces' => 0,
            ]
        );
    }

    protected function parseBaggageWeight(string $baggage): int
    {
        if (preg_match('/(\d+)/', $baggage, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    protected function calculateArrivalTime(Carbon $departure, ?string $arrivalTime): ?Carbon
    {
        if (!$arrivalTime) {
            return null;
        }

        try {
            [$hour, $minute] = explode(':', $arrivalTime);
            
            $arrival = $departure->copy()
                ->setTime((int) $hour, (int) $minute, 0);
            
            if ($arrival->lessThan($departure)) {
                $arrival->addDay();
            }
            
            return $arrival;
            
        } catch (\Exception $e) {
            Log::warning('Failed to parse arrival time', [
                'arrival_time' => $arrivalTime,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function getDateRangeForPeriod(int $period): array
    {
        $ranges = [
            3 => [0, 3],
            7 => [4, 7],
            30 => [8, 30],
            60 => [31, 60],
            90 => [61, 90],
            120 => [91, 120],
        ];

        [$start, $end] = $ranges[$period] ?? [0, 3];

        $startDate = now()->addDays($start)->format('Y-m-d');
        $endDate = now()->addDays($end)->format('Y-m-d');

        return [$startDate, $endDate];
    }
}