<?php

namespace App\Jobs;

use App\Models\{
    FlightClass,
    FlightFareBreakdown,
    FlightTax,
    FlightBaggage,
    FlightRule
};
use App\Services\FlightProviders\NiraProvider;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
// use Illuminate\Support\Facades\Log;


class FetchFlightFareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;
    public $backoff = 30;

    protected FlightClass $flightClass;

    public function __construct(FlightClass $flightClass)
    {
        $this->flightClass = $flightClass;
        $this->queue = 'fastJob';
    }

    public function handle(FlightServiceRepositoryInterface $repository): void
    {
        try {
            // Log::info("FetchFlightFareJob started", [
            //     'flight_class_id' => $this->flightClass->id,
            //     'class_code' => $this->flightClass->class_code,
            // ]);

            $this->flightClass->load(['flight.route']);

            $flight = $this->flightClass->flight;
            $route = $flight?->route;

            if (!$flight || !$route) {
                // Log::error("Missing flight or route", [
                //     'flight_class_id' => $this->flightClass->id,
                // ]);
                return;
            }

            $config = $repository->getServiceByCode('nira', $flight?->iata);

            if (empty($config)) {
                // Log::error("Config not found for airline", [
                //     'iata' => $route->iata,
                //     'flight_class_id' => $this->flightClass->id,
                // ]);
                return;
            }

            $provider = new NiraProvider($config);

            $fareData = $provider->getFare(
                $route->origin,
                $route->destination,
                $this->flightClass->class_code,
                $flight->departure_datetime->format('Y-m-d'),
                (string) $flight->flight_number
            );

            if (empty($fareData) || !is_array($fareData)) {
                // Log::warning("No fare data returned from API", [
                //     'flight_class_id' => $this->flightClass->id,
                //     'route' => "{$route->origin}-{$route->destination}",
                //     'class' => $this->flightClass->class_code,
                // ]);
                return;
            }

            $this->updateFlightClassPrices($fareData);

            $this->saveFareBreakdown($fareData);
            $this->saveTaxes($fareData['Taxes'] ?? []);
            $this->saveBaggage($fareData);
            $this->saveRules($fareData['CRCNRules'] ?? []);



        } catch (\Exception $e) {
            // Log::error("FetchFlightFareJob failed", [
            //     'flight_class_id' => $this->flightClass->id,
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString(),
            // ]);
            
            throw $e; 
// >>>>>>> b58712a5317c8fb0539c2a0c031bf31ff28246be
        }
    }

    protected function updateFlightClassPrices(array $fareData): void
    {
        $this->flightClass->update([
            'payable_child' => $fareData['ChildTotalPrice'] ?? null,
            'payable_infant' => $fareData['InfantTotalPrice'] ?? null,
            'updated_at' => now(),
        ]);

        // Log::debug("Prices updated", [
        //     'flight_class_id' => $this->flightClass->id,
        //     'adult' => $this->flightClass->payable_adult,
        //     'child' => $fareData['ChildTotalPrice'] ?? null,
        //     'infant' => $fareData['InfantTotalPrice'] ?? null,
        // ]);
    }


    protected function saveFareBreakdown(array $fareData): void
    {
        FlightFareBreakdown::updateOrCreate(
            ['flight_class_id' => $this->flightClass->id],
            [
                'base_adult' => $fareData['AdultFare'] ?? null,
                'base_child' => $fareData['ChildFare'] ?? null,
                'base_infant' => $fareData['InfantFare'] ?? null,
                'updated_at' => now(),
            ]
        );

        // Log::debug("Fare breakdown saved", [
        //     'flight_class_id' => $this->flightClass->id,
        // ]);
    }


    protected function saveTaxes(array $taxesData): void
    {
        if (empty($taxesData) || !is_array($taxesData)) {
            // Log::warning("No taxes data", [
            //     'flight_class_id' => $this->flightClass->id,
            // ]);
            return;
        }

        foreach ($taxesData as $passengerType => $taxes) {
            $taxValues = [
                'HL' => 0,
                'I6' => 0,
                'LP' => 0,
                'V0' => 0,
                'YQ' => 0,
            ];

            if (!is_array($taxes)) {
                continue;
            }

            foreach ($taxes as $taxItem) {
                if (!is_array($taxItem)) {
                    continue;
                }

                foreach ($taxItem as $key => $value) {
                    if (str_starts_with($key, 'Tax-')) {
                        $taxCode = str_replace('Tax-', '', $key);

                        if (array_key_exists($taxCode, $taxValues)) {
                            $taxValues[$taxCode] = (float) $value;
                        }
                    }
                }
            }

            FlightTax::updateOrCreate(
                [
                    'flight_class_id' => $this->flightClass->id,
                    'passenger_type' => strtolower($passengerType),
                ],
                $taxValues
            );
        }

        // Log::debug("Taxes saved", [
        //     'flight_class_id' => $this->flightClass->id,
        //     'passenger_types' => array_keys($taxesData),
        // ]);
    }


    protected function saveBaggage(array $fareData): void
    {
        $weight = $this->parseBaggageValue($fareData['BaggageAllowanceWeight'] ?? null);
        $pieces = $this->parseBaggageValue($fareData['BaggageAllowancePieces'] ?? null);

        if ($weight === null && $pieces === null) {
            // Log::warning("No baggage data", [
            //     'flight_class_id' => $this->flightClass->id,
            // ]);
            return;
        }

        FlightBaggage::updateOrCreate(
            ['flight_class_id' => $this->flightClass->id],
            [
                'adult_weight' => $weight,
                'adult_pieces' => $pieces,
                'child_weight' => null,
                'child_pieces' => null,
                'infant_weight' => null,
                'infant_pieces' => null,
            ]
        );

        // Log::debug("Baggage saved", [
        //     'flight_class_id' => $this->flightClass->id,
        //     'weight' => $weight,
        //     'pieces' => $pieces,
        // ]);
    }


    protected function parseBaggageValue(?string $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        if (preg_match('/(\d+)/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }


    protected function saveRules(array $rules): void
    {
        if (empty($rules) || !is_array($rules)) {
            // Log::warning("No rules data", [
            //     'flight_class_id' => $this->flightClass->id,
            // ]);
            return;
        }

        FlightRule::where('flight_class_id', $this->flightClass->id)->delete();

        $savedCount = 0;

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            FlightRule::updateOrCreate([
                'flight_class_id' => $this->flightClass->id,
                'rules' => $rule['text'] ?? null,
                'penalty_percentage' => isset($rule['percent']) ? (int) $rule['percent'] : null,
            ]);

            $savedCount++;
        }
//
    }


    public function failed(\Throwable $exception): void
    {
//
    }
}
