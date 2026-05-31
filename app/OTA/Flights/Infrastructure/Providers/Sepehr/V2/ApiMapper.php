<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2;

use App\Services\OTA\Flights\Domain\Entities\V2\Flight;
use App\Services\OTA\Flights\Domain\Entities\V2\FlightClass;
use App\Services\OTA\Flights\Domain\Entities\V2\FlightCollection;
use App\Services\OTA\Flights\Domain\Enums\CabinType;
use App\Services\OTA\Flights\Domain\Enums\FlightType;
use App\Services\OTA\Flights\Domain\Enums\PassengerType;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\AirportCode;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\Baggage;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\CancellationPolicy;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\Date;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\Duration;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\FlightNumber;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\Money;
use App\Services\OTA\Flights\Domain\ValueObjects\V2\PassengerFare;

final class ApiMapper
{
    public function __construct(
        private readonly bool $details = false,
        private readonly string|int|false $branch = false,
    ) {}

    public function mapSearchResponse(array $apiResponse, array $supplierConfig): FlightCollection
    {
        $collection = new FlightCollection();
        
        if (!isset($apiResponse['ItineraryList']) || empty($apiResponse['ItineraryList'])) {
            return $collection;
        }

        foreach ($apiResponse['ItineraryList'] as $itinerary) {
            if (!isset($itinerary['FlightSegmentList']) || empty($itinerary['FlightSegmentList'])) {
                continue;
            }

            foreach ($itinerary['FlightSegmentList'] as $segment) {
                $flight = $this->mapSegmentToFlight($segment, $supplierConfig);
                
                if (isset($segment['FlightClass'])) {
                    $class = $this->mapFlightClass($segment['FlightClass'], $segment, $supplierConfig);
                    $flight->addClass($class);
                }
                
                $collection->add($flight);
            }
        }

        return $collection;
    }

    private function mapSegmentToFlight(array $segment, array $supplierConfig): Flight
    {
        $flightType = ($segment['IsFlightOwnedBySupplier'] ?? false) 
            ? FlightType::CHARTER 
            : FlightType::WEBSERVICE;

        return new Flight(
            serviceId: (string) $supplierConfig['id'],
            serviceBranch: (string) $supplierConfig['branch'],
            isHub: $supplierConfig['isHub'],
            flightType: $flightType,
            flightNumber: new FlightNumber(
                number: $segment['FlightNumber'] ?? '',
                airlineIata: $segment['Airline'] ?? '',
            ),
            origin: new AirportCode(
                iata: $segment['Origin']['Code'] ?? '',
                terminal: $segment['Origin']['Terminal'] ?? null,
            ),
            destination: new AirportCode(
                iata: $segment['Destination']['Code'] ?? '',
                terminal: $segment['Destination']['Terminal'] ?? null,
            ),
            departureDateTime: Date::fromGregorian($segment['DepartureDateTime'] ?? ''),
            arrivalDateTime: Date::fromGregorian($segment['ArrivalDateTime'] ?? ''),
            duration: new Duration((int) ($segment['Duration'] ?? 0)),
            aircraftIata: $segment['Aircraft'] ?? '',
            airlineIata: $segment['Airline'] ?? '',
            remarks: $segment['Remarks'] ?? null,
            isOwnedBySupplier: $segment['IsFlightOwnedBySupplier'] ?? false,
            stop1: isset($segment['Stop1']) ? $this->mapStop($segment['Stop1']) : null,
            stop2: isset($segment['Stop2']) ? $this->mapStop($segment['Stop2']) : null,
            permittedNationalities: $segment['PermittedNationalityList'] ?? ['All'],
            prohibitedNationalities: $segment['ProhibitedNationalityList'] ?? [],
        );
    }

    private function mapFlightClass(array $flightClassData, array $segment, array $supplierConfig): FlightClass
    {
        $cabinType = CabinType::tryFrom($flightClassData['CabinType'] ?? 'Economy') 
            ?? CabinType::ECONOMY;

        return new FlightClass(
            bookingCode: $flightClassData['BookingCode'] ?? '',
            fareName: $flightClassData['FareName'] ?? '',
            cabinType: $cabinType,
            availableSeat: $flightClassData['AvailableSeat'] ?? 0,
            adultFare: $this->mapPassengerFare($flightClassData['AdultFare'] ?? [], PassengerType::ADULT),
            childFare: $this->mapPassengerFare($flightClassData['ChildFare'] ?? [], PassengerType::CHILD),
            infantFare: $this->mapPassengerFare($flightClassData['InfantFare'] ?? [], PassengerType::INFANT),
            adultBaggage: $this->mapBaggage($flightClassData['AdultFreeBaggage'] ?? []),
            childBaggage: $this->mapBaggage($flightClassData['ChildFreeBaggage'] ?? []),
            infantBaggage: $this->mapBaggage($flightClassData['InfantFreeBaggage'] ?? []),
            cancellationPolicy: $this->mapCancellationPolicy($flightClassData['CancelationPolicyList'] ?? []),
            restrictedForTour: $flightClassData['RestrictedForTour'] ?? false,
            bookingPolicy: null,
        );
    }

    private function mapPassengerFare(array $fareData, PassengerType $type): PassengerFare
    {
        return new PassengerFare(
            $type,
            new Money((int) ($fareData['BaseFare'] ?? 0)),
            new Money((int) ($fareData['Tax'] ?? 0)),
            new Money((int) ($fareData['TotalFare'] ?? 0)),
            new Money((int) ($fareData['Commission'] ?? 0)),
            new Money((int) ($fareData['Markup'] ?? 0)),
            new Money((int) ($fareData['Payable'] ?? 0)),
        );
    }

    private function mapBaggage(array $baggageData): Baggage
    {
        return new Baggage(
            (int) ($baggageData['CheckedBaggageQuantity'] ?? 0),
            (int) ($baggageData['CheckedBaggageTotalWeight'] ?? 0),
            (int) ($baggageData['HandBaggageQuantity'] ?? 0),
            (int) ($baggageData['HandBaggageTotalWeight'] ?? 0),
        );
    }

    private function mapCancellationPolicy(array $policies): ?CancellationPolicy
    {
        if (empty($policies)) return null;

        $faText = '';
        $enText = '';
        foreach ($policies as $policy) {
            if (($policy['Culture'] ?? '') === 'fa-IR') {
                $faText = $policy['Text'] ?? '';
            } else {
                $enText = $policy['Text'] ?? '';
            }
        }

        return new CancellationPolicy($faText, $enText);
    }

    private function mapStop(array $stopData): array
    {
        return [
            'StopoverAirport' => $stopData['AirportIataCode'] ?? '',
            'TimeFromStartToStop' => $stopData['StopDurationInMinute'] ?? 0,
            'StopTime' => $stopData['StopDurationInMinute'] ?? 0,
            'ArrivalDateTime' => $stopData['ArrivalDateTime'] ?? '',
            'DepartureDateTime' => $stopData['DepartureDateTime'] ?? '',
        ];
    }
}