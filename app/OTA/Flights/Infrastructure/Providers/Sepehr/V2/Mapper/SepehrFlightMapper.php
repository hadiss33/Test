<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Mapper;

use App\Services\OTA\Flights\Domain\Entities\Flight;
use App\Services\OTA\Flights\Domain\Entities\FlightClass;
use App\Services\OTA\Flights\Domain\Entities\BookingResult;
use App\Services\OTA\Flights\Domain\Entities\PassengerTicket;
use App\Services\OTA\Flights\Domain\Enums\CabinType;
use App\Services\OTA\Flights\Domain\Enums\FlightRoute;
use App\Services\OTA\Flights\Domain\Enums\FlightType;
use App\Services\OTA\Flights\Domain\ValueObjects\Baggage;
use App\Services\OTA\Flights\Domain\ValueObjects\BaggageAllowance;
use App\Services\OTA\Flights\Domain\ValueObjects\BookingPolicy;
use App\Services\OTA\Flights\Domain\ValueObjects\FlightRemarks;
use App\Services\OTA\Flights\Domain\ValueObjects\Stopover;
use App\Services\OTA\Flights\Infrastructure\Persistence\ApiMapper;
use Illuminate\Support\Facades\DB;

class SepehrFlightMapper
{
    public function __construct(
        private readonly ApiMapper            $apiMapper,
        private readonly SepehrSupplierMapper $supplierMapper,
        private readonly SepehrFinancialMapper $financialMapper,
    ) {}

    // ─── Public entry points ─────────────────────────────────────────────────────

    /**
     * @return Flight[]
     */
    public function mapSearchResponse(array $apiResults, bool $withDetails, int|string $branch): array
    {
        $flights = [];
        foreach ($apiResults as $dataItem) {
            if (!$dataItem['status']) continue;

            foreach ($dataItem['CharterFlights'] as $item) {
                $flights[] = $this->mapCharterFlight($item, $dataItem, $withDetails, $branch);
            }
            foreach ($dataItem['WebserviceFlights'] as $item) {
                $flights[] = $this->mapWebserviceFlight($item, $dataItem, $withDetails, $branch);
            }
        }
        return $flights;
    }

    public function mapBookingResponse(array $apiResponse, array $subData): BookingResult
    {
        $passengers = [];
        foreach ($apiResponse['PassengerList'] as $item) {
            $seg          = $item['DepartureSegment'];
            $passengers[] = new PassengerTicket(
                servicePnr:          $apiResponse['LocalPnr'],
                originalPnr:         $seg['OriginalPnr'],
                localTicketNumber:   $seg['LocalTicketNumber'],
                originalTicketNumber:$seg['OriginalTicketNumber'],
                flightNumber:        $seg['FlightNumber'],
            );
        }

        return new BookingResult(
            status:              true,
            localPnr:            $apiResponse['LocalPnr'],
            originIata:          $subData['origin']['iata'] ?? '',
            destinationIata:     $subData['destination']['iata'] ?? '',
            originTerminal:      $subData['origin']['terminal'] ?? false,
            destinationTerminal: $subData['destination']['terminal'] ?? false,
            flightDateTime:      $subData['departureDateTime'] ?? '',
            description:         $subData['description'] ?? '',
            passengers:          $passengers,
        );
    }

    // ─── Charter flights ─────────────────────────────────────────────────────────

    private function mapCharterFlight(array $item, array $dataItem, bool $withDetails, int|string $branch): Flight
    {
        $origin      = $this->resolveAirport($item['Origin']['Code'], $withDetails);
        $destination = $this->resolveAirport($item['Destination']['Code'], $withDetails);
        $flightRoute = $this->resolveFlightRoute($item['Origin']['Code'], $item['Destination']['Code']);

        $policy = $this->mapBookingPolicy($item['Classes'][0]['BookingPolicy'] ?? null);

        $classes = [];
        foreach ($item['Classes'] as $class) {
            $classes[] = $this->mapCharterClass($class, $item, $dataItem, $withDetails, $branch);
        }

        return new Flight(
            service:            'sepehr',
            serviceId:          $dataItem['serviceId'],
            serviceBranch:      $dataItem['serviceBranch'],
            displayableService: $dataItem['isHub'] ? 'airplusHub' : 'sepehr',
            verified:           $item['SystemSupplier'] == 34,
            flightType:         FlightType::Charter,
            flightRoute:        $flightRoute,
            flightNumber:       $item['FlightNumber'],
            origin:             ['iata' => $origin, 'terminal' => !is_null($item['Origin']['Terminal'])],
            destination:        ['iata' => $destination, 'terminal' => !is_null($item['Destination']['Terminal'])],
            departureDateTime:  $item['DepartureDateTime'] . ':00',
            arrivalDateTime:    $item['ArrivalDateTime'] . ':00',
            duration:           $item['Duration'],
            aircraft:           $withDetails ? $this->apiMapper->getAircraft($item['Aircraft']) : $item['Aircraft'],
            airline:            $withDetails ? $this->apiMapper->getAirline($item['Airline']) : $item['Airline'],
            remarks:            $this->mapFlightRemarks($item, $policy),
            returningFlight:    $policy ? $this->mapReturningFlight($policy, $item) : false,
            steps:              $this->mapSteps($item),
            classes:            $classes,
        );
    }

    private function mapCharterClass(array $class, array $item, array $dataItem, bool $withDetails, int|string $branch): FlightClass
    {
        $hasMarkup    = $this->shouldApplyMarkup($branch);
        $financial    = $this->financialMapper->mapFinancial(
            adultFare:  $class['AdultFare'],
            childFare:  $class['ChildFare'],
            infantFare: $class['InfantFare'],
            markupAdl:  $hasMarkup ? 0 : 0,  // placeholder - markup calculation می‌تواند اینجا inject شود
        );
        $baseFinancial = $this->financialMapper->mapBaseFinancial(
            $class['AdultFare'], $class['ChildFare'], $class['InfantFare']
        );

        $niraSupplier   = isset($item['Nira']) && $item['Nira']
            ? $this->supplierMapper->resolveNiraSupplier($item['Airline'], $item['SystemSupplier'])
            : null;

        $supplier = $withDetails
            ? ($niraSupplier && !$dataItem['isHub']
                ? $this->supplierMapper->getSupplier($niraSupplier['id'])
                : $this->supplierMapper->getSupplier($item['SystemSupplier'], $dataItem['isHub']))
            : $item['SystemSupplier'];

        $systemSupplier = $withDetails
            ? $this->supplierMapper->getSystemSupplier($item['SystemSupplier'])
            : $item['SystemSupplier'];

        $policy  = $this->mapBookingPolicy($class['BookingPolicy'] ?? null);
        $remarks = $policy ? new FlightRemarks(
            airlineSupplier:    false,
            tourRequirement:    $policy->restrictedForTour,
            oneWayRequirement:  $policy->returningFlightMustNotEqualToAnyFlight,
            roundtripRequirement: $policy->restrictedForTour,
            phoneRequirement:   false,
            description:        $class['CancelationPolicy'] ?? false,
            special:            false,
            warranty:           false,
        ) : false;

        return new FlightClass(
            flightStatus:      true,
            reservable:        true,
            status:            'reservable',
            cancelationPolicy: $class['CancelationPolicy'] ?? false,
            bookingPolicy:     $policy,
            supplier:          is_array($supplier) ? $supplier : ['id' => $supplier],
            systemSupplier:    is_array($systemSupplier) ? $systemSupplier : ['id' => $systemSupplier],
            flightId:          $class['BookingCode'],
            fareName:          $class['FareName'] ?? false,
            cabinType:         $this->mapCabinType($class['CabinType'], $withDetails),
            availableSeat:     $class['AvailableSeat'],
            rules:             false,
            financial:         $financial,
            baseData:          [
                'Supplier'  => ['Supplier' => $supplier, 'SystemSupplier' => $systemSupplier],
                'Financial' => $baseFinancial,
            ],
            baggage:           $this->mapBaggage($class),
            inboundRemarks:    $remarks,
            outboundPolicy:    $policy && $policy->returningFlightMustEqualToAnyFlight ? $policy : false,
        );
    }

    // ─── Webservice flights ──────────────────────────────────────────────────────

    private function mapWebserviceFlight(array $item, array $dataItem, bool $withDetails, int|string $branch): Flight
    {
        $origin      = $this->resolveAirport($item['Origin']['Code'], $withDetails);
        $destination = $this->resolveAirport($item['Destination']['Code'], $withDetails);
        $flightRoute = $this->resolveFlightRoute($item['Origin']['Code'], $item['Destination']['Code']);

        $classes = [];
        foreach ($item['Classes'] as $class) {
            $classes[] = $this->mapWebserviceClass($class, $item, $dataItem, $withDetails, $branch);
        }

        $remarks = new FlightRemarks(
            airlineSupplier:      $item['IsParvazSystemiAirline'] ?? false,
            tourRequirement:      false,
            oneWayRequirement:    false,
            roundtripRequirement: false,
            phoneRequirement:     false,
            description:          $item['CancelationPolicy'] ?? false,
            special:              false,
            warranty:             false,
        );

        return new Flight(
            service:            'sepehr',
            serviceId:          $dataItem['serviceId'],
            serviceBranch:      $dataItem['serviceBranch'],
            displayableService: $dataItem['isHub'] ? 'airplusHub' : 'sepehr',
            verified:           $item['SystemSupplier'] == 34,
            flightType:         FlightType::WebService,
            flightRoute:        $flightRoute,
            flightNumber:       $item['FlightNumber'],
            origin:             ['iata' => $origin, 'terminal' => !is_null($item['Origin']['Terminal'])],
            destination:        ['iata' => $destination, 'terminal' => !is_null($item['Destination']['Terminal'])],
            departureDateTime:  $item['DepartureDateTime'] . ':00',
            arrivalDateTime:    $item['ArrivalDateTime'] . ':00',
            duration:           $item['Duration'],
            aircraft:           $withDetails ? $this->apiMapper->getAircraft($item['Aircraft']) : $item['Aircraft'],
            airline:            $withDetails ? $this->apiMapper->getAirline($item['Airline']) : $item['Airline'],
            remarks:            $remarks,
            returningFlight:    ['AllowedReturnFlights' => ['FlightNumber' => false, 'FlightDate' => false], 'UnauthorizedReturnFlights' => false, 'ReturnOnlyFromTheAirlineOfOrigin' => false, 'ReturnFlightProvider' => false, 'DistanceToReturnFlight' => ['Min' => 0, 'Max' => 0]],
            steps:              $this->mapSteps($item),
            classes:            $classes,
        );
    }

    private function mapWebserviceClass(array $class, array $item, array $dataItem, bool $withDetails, int|string $branch): FlightClass
    {
        // reservable بر اساس IsAirlineScheduleFlight یا IsParvazSystemiAirline
        if (isset($item['IsAirlineScheduleFlight'])) {
            $reservable = !$item['IsAirlineScheduleFlight'];
        } elseif (isset($item['IsParvazSystemiAirline'])) {
            $reservable = !$item['IsParvazSystemiAirline'];
        } else {
            $reservable = false;
        }

        $hasRoundtrip = isset($class['RoundtripFare_FromOrigin']) && isset($class['RoundtripFare_FromDestination']);
        $financial    = $this->financialMapper->mapFinancial(
            adultFare:    $class['AdultFare'],
            childFare:    $class['ChildFare'],
            infantFare:   $class['InfantFare'],
            roundtripData:$hasRoundtrip ? $class : false,
        );

        $baseFinancial = $this->financialMapper->mapBaseFinancial(
            $class['AdultFare'], $class['ChildFare'], $class['InfantFare']
        );

        $niraSupplier = isset($item['Nira']) && $item['Nira']
            ? $this->supplierMapper->resolveNiraSupplier($item['Airline'], $item['SystemSupplier'])
            : null;

        $supplier = $withDetails
            ? ($niraSupplier && !$dataItem['isHub']
                ? $this->supplierMapper->getSupplier($niraSupplier['id'])
                : $this->supplierMapper->getSupplier($item['SystemSupplier'], $dataItem['isHub']))
            : $item['SystemSupplier'];

        $systemSupplier = $withDetails
            ? $this->supplierMapper->getSystemSupplier($item['SystemSupplier'])
            : $item['SystemSupplier'];

        $policy  = $this->mapBookingPolicy($class['BookingPolicy'] ?? null);
        $remarks = $policy ? new FlightRemarks(
            airlineSupplier:      false,
            tourRequirement:      $policy->restrictedForTour,
            oneWayRequirement:    $policy->returningFlightMustNotEqualToAnyFlight,
            roundtripRequirement: $policy->restrictedForTour,
            phoneRequirement:     false,
            description:          $class['CancelationPolicy'] ?? false,
            special:              false,
            warranty:             false,
        ) : false;

        return new FlightClass(
            flightStatus:      true,
            reservable:        $reservable,
            status:            $reservable ? 'reservable' : 'full',
            cancelationPolicy: $class['CancelationPolicy'] ?? false,
            bookingPolicy:     $policy,
            supplier:          is_array($supplier) ? $supplier : ['id' => $supplier],
            systemSupplier:    is_array($systemSupplier) ? $systemSupplier : ['id' => $systemSupplier],
            flightId:          $class['BookingCode'],
            fareName:          $class['FareName'] ?? false,
            cabinType:         $this->mapCabinType($class['CabinType'], $withDetails),
            availableSeat:     $class['AvailableSeat'],
            rules:             false,
            financial:         $financial,
            baseData:          [
                'Supplier'  => ['Supplier' => $supplier, 'SystemSupplier' => $systemSupplier],
                'Financial' => $baseFinancial,
            ],
            baggage:           $this->mapBaggage($class),
            inboundRemarks:    $remarks,
            outboundPolicy:    $policy && $policy->returningFlightMustEqualToAnyFlight ? $policy : false,
        );
    }

    // ─── Helper mappers ──────────────────────────────────────────────────────────

    private function mapBookingPolicy(?array $raw): BookingPolicy|false
    {
        if (is_null($raw)) return false;

        return new BookingPolicy(
            restrictedForTour:                    (bool) ($raw['RestrictedForTour'] ?? false),
            returningFlightMustNotEqualToAnyFlight:(bool) ($raw['ReturningFlightMustNotEqualToAnyFlight'] ?? false),
            returningFlightMustEqualToAnyFlight:  (bool) ($raw['ReturningFlightMustEqualToAnyFlight'] ?? false),
            restrictedReturningBySameAirline:     (bool) ($raw['RestrictedReturningBySameAirline'] ?? false),
            returningFlightMustEqualList:          $raw['ReturningFlightMustEqualList'] ?? false,
            returningFlightMustNotEqualList:       $raw['ReturningFlightMustNotEqualList'] ?? false,
            fareMinStayDays:                       (int) ($raw['FareMinStay']['MinimumStayDay'] ?? 0),
            fareMaxStayDays:                       (int) ($raw['FareMaxStay']['MaximumStayDay'] ?? 0),
            returnFlightSupplierId:                $raw['SupplierId'] ?? false,
        );
    }

    private function mapFlightRemarks(array $item, BookingPolicy|false $policy): FlightRemarks
    {
        return new FlightRemarks(
            airlineSupplier:      false,
            tourRequirement:      $policy ? $policy->restrictedForTour : false,
            oneWayRequirement:    $policy ? $policy->returningFlightMustNotEqualToAnyFlight : false,
            roundtripRequirement: $policy ? $policy->returningFlightMustEqualToAnyFlight : false,
            phoneRequirement:     false,
            description:          $item['Remarks'] ?? false,
            special:              false,
            warranty:             false,
        );
    }

    private function mapReturningFlight(BookingPolicy $policy, array $item): array|false
    {
        if (!$policy->returningFlightMustEqualToAnyFlight) return false;

        return [
            'AllowedReturnFlights'          => $policy->returningFlightMustEqualList,
            'UnauthorizedReturnFlights'      => $policy->returningFlightMustNotEqualList,
            'ReturnOnlyFromTheAirlineOfOrigin' => $policy->restrictedReturningBySameAirline,
            'ReturnFlightProvider'           => $policy->returnFlightSupplierId,
            'DistanceToReturnFlight'         => [
                'Min' => $policy->fareMinStayDays,
                'Max' => $policy->fareMaxStayDays,
            ],
        ];
    }

    private function mapBaggage(array $class): Baggage
    {
        return new Baggage(
            adult: new BaggageAllowance(
                trunkNumber: $class['AdultFreeBaggage']['CheckedBaggageQuantity'] ?? false,
                trunkWeight: $class['AdultFreeBaggage']['CheckedBaggageTotalWeight'] ?? false,
                handNumber:  $class['AdultFreeBaggage']['HandBaggageQuantity'] ?? false,
                handWeight:  $class['AdultFreeBaggage']['HandBaggageTotalWeight'] ?? false,
            ),
            child: new BaggageAllowance(
                trunkNumber: $class['ChildFreeBaggage']['CheckedBaggageQuantity'] ?? false,
                trunkWeight: $class['ChildFreeBaggage']['CheckedBaggageTotalWeight'] ?? false,
                handNumber:  $class['ChildFreeBaggage']['HandBaggageQuantity'] ?? false,
                handWeight:  $class['ChildFreeBaggage']['HandBaggageTotalWeight'] ?? false,
            ),
            infant: new BaggageAllowance(
                trunkNumber: $class['InfantFreeBaggage']['CheckedBaggageQuantity'] ?? false,
                trunkWeight: $class['InfantFreeBaggage']['CheckedBaggageTotalWeight'] ?? false,
                handNumber:  $class['InfantFreeBaggage']['HandBaggageQuantity'] ?? false,
                handWeight:  $class['InfantFreeBaggage']['HandBaggageTotalWeight'] ?? false,
            ),
        );
    }

    /** @return Stopover[] */
    private function mapSteps(array $item): array|false
    {
        $steps = [];
        foreach (['Step1', 'Step2'] as $key) {
            if (isset($item[$key])) {
                $steps[] = new Stopover(
                    airportIata:         $item[$key]['AirportIataCode'],
                    stopDurationMinutes: $item[$key]['StopDurationInMinute'],
                    arrivalDateTime:     $item[$key]['ArrivalDateTime'],
                    departureDateTime:   $item[$key]['DepartureDateTime'],
                );
            }
        }
        return count($steps) > 0 ? $steps : false;
    }

    private function mapCabinType(string $raw, bool $withDetails): array
    {
        $cabin = CabinType::fromApiValue($raw);
        if (!$withDetails) {
            return ['iata' => $cabin->iata()];
        }
        return [
            'iata'  => $cabin->iata(),
            'title' => ['fa' => $cabin->titleFa(), 'en' => $cabin->value],
        ];
    }

    private function resolveAirport(string $iata, bool $withDetails): array|string
    {
        return $withDetails ? $this->apiMapper->getAirport($iata) : $iata;
    }

    private function resolveFlightRoute(string $originIata, string $destinationIata): FlightRoute
    {
        $origin      = DB::table('airports')->select('country')->where('iata', $originIata)->first();
        $destination = DB::table('airports')->select('country')->where('iata', $destinationIata)->first();
        $countryCode = 118;

        if ($origin?->country !== $countryCode || $destination?->country !== $countryCode) {
            return FlightRoute::International;
        }
        return FlightRoute::Internal;
    }

    private function shouldApplyMarkup(int|string $branch): bool
    {
        if (!$branch) return false;
        $cleanBranch = str_replace('b2c-', '', (string) $branch);
        if (str_contains((string) $branch, 'b2c-')) return false;

        $row = DB::table('offices')->select('base_online')->where('id', $cleanBranch)->first();
        return is_null($row?->base_online);
    }
}