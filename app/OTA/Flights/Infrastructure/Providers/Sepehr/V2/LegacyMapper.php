<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2;

use App\Services\OTA\Flights\Domain\Entities\V2\Flight;
use App\Services\OTA\Flights\Domain\Entities\V2\FlightClass;
use Illuminate\Support\Facades\DB;

final class LegacyMapper
{
    public function __construct(
        private readonly bool $details = false,
        private readonly string|int|false $branch = false,
    ) {}

    public function map(Flight $flight): array
    {
        return [
            'Service' => 'sepehr',
            'ServiceId' => $flight->serviceId,
            'ServiceBranch' => $flight->serviceBranch,
            'DisplayableService' => $flight->isHub ? 'airplusHub' : 'sepehr',
            'Verified' => false,
            'FlightType' => $flight->flightType->value,
            'FlightRoute' => $this->getFlightRoute($flight),
            'FlightNumber' => $flight->flightNumber->number,
            'Origin' => $this->mapAirport($flight->origin),
            'Destination' => $this->mapAirport($flight->destination),
            'DepartureDateTime' => $flight->departureDateTime->toDateTimeString() . ':00',
            'ArrivalDateTime' => $flight->arrivalDateTime->toDateTimeString() . ':00',
            'Duration' => $flight->duration->minutes,
            'Aircraft' => $this->getAircraft($flight->aircraftIata),
            'Airline' => $this->getAirline($flight->airlineIata),
            'Remarks' => $this->buildFlightRemarks($flight),
            'ReturningFlight' => false,
            'Steps' => $this->buildSteps($flight),
            'Classes' => $this->mapClasses($flight),
        ];
    }

    private function mapAirport($airport): array
    {
        if (!$this->details) {
            return ['Iata' => $airport->iata, 'Terminal' => !is_null($airport->terminal)];
        }
        return ['Iata' => $this->getAirportDetails($airport->iata), 'Terminal' => !is_null($airport->terminal)];
    }

    private function getFlightRoute(Flight $flight): string
    {
        $originCountry = DB::table('airports')->select('country')->where('iata', $flight->origin->iata)->first()?->country;
        $destinationCountry = DB::table('airports')->select('country')->where('iata', $flight->destination->iata)->first()?->country;
        return ($originCountry !== env('COUNTRY_CODE') || $destinationCountry !== env('COUNTRY_CODE')) ? 'International' : 'Internal';
    }

    private function buildFlightRemarks(Flight $flight): array
    {
        return [
            'AirlineSupplier' => false,
            'TourRequirement' => false,
            'OneWayRequirement' => false,
            'RoundtripRequirement' => false,
            'PhoneRequirement' => false,
            'Description' => $flight->remarks ?? false,
            'Special' => false,
            'Warranty' => false,
        ];
    }

    private function buildSteps(Flight $flight): array|false
    {
        $steps = [];
        if ($flight->stop1) $steps[] = $flight->stop1;
        if ($flight->stop2) $steps[] = $flight->stop2;
        return empty($steps) ? false : $steps;
    }

    private function mapClasses(Flight $flight): array
    {
        $result = [];
        foreach ($flight->getClasses() as $class) {
            $result[] = $this->mapClass($class, $flight);
        }
        return $result;
    }

    private function mapClass(FlightClass $class, Flight $flight): array
    {
        $isReservable = $flight->flightType->value !== 'WebService' || $flight->isOwnedBySupplier;

        return [
            'FlightStatus' => true,
            'Reservable' => $isReservable,
            'Status' => $isReservable ? 'reservable' : 'full',
            'CancelationPolicy' => true,
            'BookingPolicy' => !is_null($class->bookingPolicy),
            'Supplier' => $this->getSupplier($flight),
            'SystemSupplier' => $this->getSystemSupplier($flight),
            'FlightId' => $class->bookingCode,
            'FareName' => $class->fareName,
            'CabinType' => $this->details ? $class->cabinType->toIataDetailed() : $class->cabinType->toIata(),
            'AvailableSeat' => $class->availableSeat,
            'Rules' => false,
            'Financial' => $this->buildFinancial($class),
            'BaseData' => [
                'Supplier' => [
                    'Supplier' => $this->getSupplier($flight),
                    'SystemSupplier' => $this->getSystemSupplier($flight),
                ],
                'Financial' => $this->buildBaseDataFinancial($class),
            ],
            'Baggage' => [
                'Adult' => $class->adultBaggage->toArray(),
                'Child' => $class->childBaggage->toArray(),
                'Infant' => $class->infantBaggage->toArray(),
            ],
            'Remarks' => $this->buildClassRemarks($class),
        ];
    }

    private function buildFinancial(FlightClass $class): array
    {
        return [
            'PriceAdditions' => ['Citizens' => 0],
            'CommissionPaid' => [
                'Percentage' => false,
                'Transaction' => env('SEPEHR_TRANSACTION'),
                'MembershipRight' => false,
            ],
            'Adult' => $this->buildPassengerFinancial($class->adultFare),
            'Child' => $this->buildPassengerFinancial($class->childFare),
            'Infant' => $this->buildPassengerFinancial($class->infantFare),
            'RoundtripFare' => false,
        ];
    }

    private function buildBaseDataFinancial(FlightClass $class): array
    {
        return $this->buildFinancial($class);
    }

    private function buildPassengerFinancial($fare): array
    {
        $totalFare = $fare->totalFare->amount;
        $commission = $fare->commission->amount;

        return [
            'BaseFare' => $fare->baseFare->amount,
            'Tax' => $fare->tax->amount,
            'Markup' => 0,
            'TotalFare' => $totalFare,
            'Payable' => $fare->payable->amount,
            'Commission' => [
                'Percentage' => $totalFare > 0 ? round(($commission / $totalFare) * 100, 2) : 0,
                'Final' => $commission,
                'Price' => $commission,
            ],
        ];
    }

    private function buildClassRemarks(FlightClass $class): array|false
    {
        if (!$class->bookingPolicy && !$class->cancellationPolicy) {
            return false;
        }

        return [
            'Inbound' => [
                'AirlineSupplier' => false,
                'TourRequirement' => $class->restrictedForTour,
                'OneWayRequirement' => false,
                'RoundtripRequirement' => $class->restrictedForTour,
                'PhoneRequirement' => false,
                'Description' => $class->cancellationPolicy?->textFa ?? false,
                'Special' => false,
                'Warranty' => false,
            ],
            'Outbound' => false,
        ];
    }

    private function getSupplier(Flight $flight): array|string
    {
        if (!$this->details) return $flight->serviceBranch;
        return ['id' => $flight->serviceBranch, 'title' => 'Supplier ' . $flight->serviceBranch];
    }

    private function getSystemSupplier(Flight $flight): array|string
    {
        if (!$this->details) return $flight->serviceBranch;
        return ['id' => $flight->serviceBranch, 'title' => 'System Supplier ' . $flight->serviceBranch];
    }

    private function getAirportDetails(string $iata): array
    {
        // TODO: مشابه SepehrApi::getDetails('airport', ...)
        return ['iata' => $iata];
    }

    private function getAircraft(string $iata): array|string
    {
        if (!$this->details) return $iata;
        return ['iata' => $iata];
    }

    private function getAirline(string $iata): array|string
    {
        if (!$this->details) return $iata;
        return ['iata' => $iata];
    }
}