<?php

namespace App\Services\FlightUpdaters;

use App\Models\AirlineActiveRoute;
use App\Models\Flight;
use App\Models\FlightBaggage;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Models\FlightFareBreakdown;
use App\Models\FlightRule;
use App\Models\FlightTax;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sepehr Flight Updater
 *
 * Uses 1-step process:
 * - GetCharterFlights - Returns complete flight data including:
 *   - Flight details (number, times, aircraft)
 *   - All classes with fares
 *   - Baggage info
 *   - Cancellation policies (rules)
 *
 * NO Jobs needed - all data comes in one API call!
 */
class SepehrFlightUpdater implements FlightUpdaterInterface
{
    protected FlightProviderInterface $provider;

    protected ?string $iata;

    protected string $service;

    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service = 'sepehr')
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
            'jobs_dispatched' => 0, // Always 0 for Sepehr
        ];

        $fullConfig = $this->provider->getConfig();

        $query = AirlineActiveRoute::where('application_interfaces_id', $fullConfig['id'] ?? null);



        $routes = $query->get();

        if ($routes->isEmpty()) {
            Log::warning('No routes found for Sepehr service');

            return $stats;
        }

        $stats['routes_processed'] = $routes->count();

        [$startDate, $endDate] = $this->getDateRangeForPeriod($period);

        try {
            $rawData = $this->provider->getCharterFlights($startDate, $endDate);

            if (empty($rawData['CharterFlightList'])) {
                Log::warning('Sepehr GetCharterFlights returned no flights', [
                    'period' => $period,
                    'from' => $startDate,
                    'to' => $endDate,
                ]);

                return $stats;
            }

            $flightsList = $rawData['CharterFlightList'];
            $stats['flights_found'] = count($flightsList);

            // Process each flight
            foreach ($flightsList as $flightData) {
                $this->processFlightData($flightData, $routes, $stats);
            }

            Log::info("Sepehr update completed - Period {$period}", $stats);

        } catch (\Exception $e) {
            $stats['errors']++;
            Log::error('Sepehr updateByPeriod failed', [
                'period' => $period,
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
            // Find matching route
            $origin = $flightData['Origin']['Code'];
            $destination = $flightData['Destination']['Code'];

            $route = $routes->first(function ($r) use ($origin, $destination) {
                return $r->origin === $origin && $r->destination === $destination;
            });

            if (! $route) {
                Log::debug('Sepehr flight route not in active routes', [
                    'origin' => $origin,
                    'destination' => $destination,
                    'flight_no' => $flightData['FlightNumber'] ?? 'unknown',
                ]);
                DB::rollBack();

                return;
            }

            // Create/Update Flight
            $departureDateTime = Carbon::parse($flightData['DepartureDateTime']);

            $flight = Flight::updateOrCreate(
                [
                    'airline_active_route_id' => $route->id,
                    'flight_number' => $flightData['FlightNumber'],
                    'departure_datetime' => $departureDateTime,
                    'iata'=>  $flightData['Airline'],
                ],
                [
                    'updated_at' => now(),
                    'missing_count' => 0,
                ]
            );

            $isNewFlight = $flight->wasRecentlyCreated;

            // Create/Update FlightDetail
            FlightDetail::updateOrCreate(
                ['flight_id' => $flight->id],
                [
                    'arrival_datetime' => isset($flightData['ArrivalDateTime'])
                        ? Carbon::parse($flightData['ArrivalDateTime'])
                        : null,
                    'aircraft_code' => $flightData['Aircraft'] ?? null,
                    'aircraft_type_code' => $flightData['Aircraft'] ?? null,
                    'updated_at' => now(),
                ]
            );

            $hasChanges = false;

            // Process all flight classes
            if (isset($flightData['FlightClassList']) && is_array($flightData['FlightClassList'])) {
                foreach ($flightData['FlightClassList'] as $classData) {
                    $changed = $this->saveCompleteFlightClass($flight, $classData);
                    if ($changed) {
                        $hasChanges = true;
                    }
                }
            }

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

            Log::error('Sepehr processFlightData error', [
                'flight_no' => $flightData['FlightNumber'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Save FlightClass with ALL related data (Fare, Baggage, Rules)
     *
     * This is different from Nira - we don't need a Job!
     */
    protected function saveCompleteFlightClass(Flight $flight, array $classData): bool
    {
        $classCode = $classData['BookingCode'];
        $availableSeats = $classData['AvailableSeat'] ?? 0;

        // Determine status
        $status = 'active';
        if ($availableSeats <= 0) {
            $status = 'full';
        }

        // Extract Adult fare (Sepehr always provides OnewayFare)
        $adultFare = $classData['OnewayFare']['Adult_Fare'] ?? [];

        // Create/Update FlightClass
        $flightClass = FlightClass::updateOrCreate(
            [
                'flight_id' => $flight->id,
                'class_code' => $classCode,
            ],
            [
                'payable_adult' => (float) ($adultFare['Payable'] ?? 0),
                'payable_child' => (float) ($classData['OnewayFare']['Child_Fare']['Payable'] ?? 0),
                'payable_infant' => (float) ($classData['OnewayFare']['Infant_Fare']['Payable'] ?? 0),
                'available_seats' => $availableSeats,
                'status' => $status,
                'updated_at' => now(),
            ]
        );

        $wasChanged = $flightClass->wasRecentlyCreated || $flightClass->wasChanged();

        // Save Fare Breakdown
        $this->saveFareBreakdown($flightClass, $classData['OnewayFare']);

        // Save Baggage
        $this->saveBaggage($flightClass, $classData);

        $this->saveRules($flightClass, $classData['CancelationPolicyList'] ?? []);

        $this->saveTax($flightClass, $classData['OnewayFare']);

        return $wasChanged;
    }

    protected function saveFareBreakdown(FlightClass $flightClass, array $fareData): void
    {
        FlightFareBreakdown::updateOrCreate(
            ['flight_class_id' => $flightClass->id],
            [
                'base_adult' => (float) ($fareData['Adult_Fare']['BaseFare'] ?? 0),
                'base_child' => (float) ($fareData['Child_Fare']['BaseFare'] ?? 0),
                'base_infant' => (float) ($fareData['Infant_Fare']['BaseFare'] ?? 0),
                'updated_at' => now(),
            ]
        );
    }

    protected function saveTax(FlightClass $flightClass, array $fareData): void
    {
        $passengers = [
            'adult' => 'Adult_Fare',
            'child' => 'Child_Fare',
            'infant' => 'Infant_Fare',
        ];

        foreach ($passengers as $type => $fareKey) {
            FlightTax::updateOrCreate(
                [
                    'flight_class_id' => $flightClass->id,
                    'passenger_type' => $type,
                ],
                [
                    'YQ' => (float) ($fareData[$fareKey]['Tax'] ?? 0),
                ]
            );
        }
    }

    protected function saveBaggage(FlightClass $flightClass, array $classData): void
    {
        $adultBaggage = $classData['AdultFreeBaggage'] ?? [];
        $childBaggage = $classData['ChildFreeBaggage'] ?? [];
        $infantBaggage = $classData['InfantFreeBaggage'] ?? [];

        FlightBaggage::updateOrCreate(
            ['flight_class_id' => $flightClass->id],
            [
                'adult_weight' => $adultBaggage['CheckedBaggageTotalWeight'] ?? 0,
                'adult_pieces' => $adultBaggage['CheckedBaggageQuantity'] ?? 0,
                'child_weight' => $childBaggage['CheckedBaggageTotalWeight'] ?? 0,
                'child_pieces' => $childBaggage['CheckedBaggageQuantity'] ?? 0,
                'infant_weight' => $infantBaggage['CheckedBaggageTotalWeight'] ?? 0,
                'infant_pieces' => $infantBaggage['CheckedBaggageQuantity'] ?? 0,
            ]
        );
    }

    protected function saveRules(FlightClass $flightClass, array $policyList): void
    {
        // Delete old rules
        FlightRule::where('flight_class_id', $flightClass->id)->delete();

        if (empty($policyList)) {
            return;
        }

        // Find Persian (fa-IR) policy
        $persianPolicy = collect($policyList)->firstWhere('Culture', 'fa-IR');

        if (! $persianPolicy || empty($persianPolicy['Text'])) {
            return;
        }


        $rulesText = $persianPolicy['Text'];
        $lines = explode("\r\n", $rulesText);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Extract percentage (e.g., "30 درصد" -> 30)
            $percentage = null;
            if (preg_match('/(\d+)\s*درصد/', $line, $matches)) {
                $percentage = (int) $matches[1];
            }

            FlightRule::create([
                'flight_class_id' => $flightClass->id,
                'rules' => $line,
                'penalty_percentage' => $percentage,
            ]);
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
