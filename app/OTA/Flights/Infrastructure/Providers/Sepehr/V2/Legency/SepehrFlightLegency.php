<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Legency;

use App\Jobs\TemporaryReservation;
use App\Lib\Auxiliary\Visa;
use App\Services\OTA\Flights\Application\Contracts\FlightProviderInterface;
use App\Services\OTA\Flights\Application\DTOs\ActiveRoutesResult;
use App\Services\OTA\Flights\Application\DTOs\BalanceResult;
use App\Services\OTA\Flights\Application\DTOs\BookFlightRequest;
use App\Services\OTA\Flights\Application\DTOs\BookFlightResult;
use App\Services\OTA\Flights\Application\DTOs\BookStatusRequest;
use App\Services\OTA\Flights\Application\DTOs\BookStatusResult;
use App\Services\OTA\Flights\Application\DTOs\LockFlightRequest;
use App\Services\OTA\Flights\Application\DTOs\LockFlightResult;
use App\Services\OTA\Flights\Application\DTOs\SearchFlightRequest;
use App\Services\OTA\Flights\Application\DTOs\SearchFlightResult;
use App\Services\OTA\Flights\Domain\Exceptions\CredentialNotFoundException;
use App\Services\OTA\Flights\Domain\Exceptions\ProviderException;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Auth\SepehrCredential;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Config\SepehrConfig;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Http\SepehrHttpClient;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Mapper\SepehrFlightMapper;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Repositories\AirportRepository;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Repositories\SepehrActiveRouteRepository;
use App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Repositories\SepehrCredentialRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Throwable;

class SepehrFlightLegency implements FlightProviderInterface
{
    private bool $isHub = false;

    public function __construct(
        private readonly SepehrHttpClient $httpClient,
        private readonly SepehrCredentialRepository $credentialRepo,
        private readonly SepehrActiveRouteRepository $activeRouteRepo,
        private readonly SepehrFlightMapper $flightMapper,
        private readonly AirportRepository $airportRepo,

    ) {}

    // ─── Search ──────────────────────────────────────────────────────────────────

    public function search(SearchFlightRequest $request): SearchFlightResult
    {
        $credentials = $this->resolveCredentials($request->branch, $request->supplierIds);

        $originId = $this->airportRepo->getAirportId($request->originIata);
        $destinationId = $this->airportRepo->getAirportId($request->destinationIata);
        $dayOfWeek = strtolower(Carbon::parse($request->departureDate)->englishDayOfWeek);

        $apiResults = [];
        foreach ($credentials as $credential) {
            $route = $this->activeRouteRepo->findRoute(
                $credential->supplierObjectId,
                $originId,
                $destinationId,
                $dayOfWeek,
            );

            if (is_null($route)) {
                continue;
            }

            $params = [
                'OriginIataCode' => $request->originIata,
                'DestinationIataCode' => $request->destinationIata,
                'DepartureDate' => $request->departureDate,
                'FetchSupplierWebserviceFlights' => false,
                'FetchFlighsWithBookingPolicy' => true,
                'Language' => 'FA',
            ];

            if ($request->fetchSupplierWebserviceFlights || $credential->hasNira) {
                $params['FetchSupplierWebserviceFlights'] = true;
            }

            try {
                $raw = $this->httpClient->post($credential, 'SearchByRouteAndDate', $params);

                if (isset($raw['ErrorMessage'])) {
                    $apiResults[] = $this->buildErrorResult($credential, Visa::addSystemReport([
                        'supplier' => $credential->supplierObjectId,
                        'Message' => $raw['ErrorMessage'],
                        'Trace' => $raw['ExceptionType'] ?? '',
                    ]));

                    continue;
                }

                $charterFlights = $this->tagFlights($raw['CharterFlights'] ?? [], $credential);
                $webserviceFlights = $this->tagFlights($raw['WebserviceFlights'] ?? [], $credential);

                $apiResults[] = [
                    'status' => true,
                    'serviceId' => $credential->id,
                    'serviceBranch' => $credential->branch,
                    'isHub' => $credential->isHub,
                    'supplier' => $credential->supplierObjectId,
                    'CurrencyCode' => 'IRR',
                    'CharterFlights' => $charterFlights,
                    'WebserviceFlights' => $webserviceFlights,
                ];
            } catch (ProviderException $e) {
                $apiResults[] = $this->buildErrorResult($credential, [
                    'Data' => ['Status' => false, 'Code' => $e->getProviderCode(), 'Message' => $e->getMessage()],
                    'Trace' => $e->getTrace(),
                ]);
            }
        }

        if (empty($apiResults)) {
            $Data = [
                'Status' => false,
                'Time' => time(),
                'Code' => '1503',
                'Message' => 'No active routes found',
            ];

            return new SearchFlightResult(
                Data: $Data,
                Result: $apiResults,
            );
        }

        $flights = $this->flightMapper->mapSearchResponse($apiResults, $request->withDetails, $request->branch);
        try {
            $Data = [
                'Status' => true,
                'Time' => time(),
                'CurrencyCode' => 'IRR',
                'Information' => $flights,
            ];

            return new SearchFlightResult(
                Data: $Data,
                Result: $apiResults,
            );

        } catch (Exception $e) {
            dd($e);   // Handle the exception
        }
    }

    public function lock(LockFlightRequest $request): LockFlightResult
    {
        $credential = $this->credentialRepo->getBySupplier($request->supplierId);

        if (! $credential) {
            throw CredentialNotFoundException::forBranch($request->branch);
        }

        try {
            $raw = $this->httpClient->post($credential, 'Lock', $request->lockData);

            // ─── خطا از Sepehr ────────────────────────────────────────────────────
            if (isset($raw['ErrorMessage'])) {
                return new LockFlightResult(
                    status: false,
                    lockId: false,
                    rawResult: $raw,
                    errorCode: '1502-'.($raw['ExceptionType'] ?? 'Exception'),
                    errorMessage: $raw['ErrorMessage'],
                );
            }

            // ─── موفق — DepartureSegmentLockId وجود داره ──────────────────────────
            if (isset($raw['DepartureSegmentLockId'])) {
                return new LockFlightResult(
                    status: true,
                    lockId: (string) $raw['DepartureSegmentLockId'],
                    rawResult: $raw,
                );
            }

            // ─── پاسخ ناشناخته ────────────────────────────────────────────────────
            return new LockFlightResult(
                status: false,
                lockId: false,
                rawResult: $raw,
                errorCode: '1502',
                errorMessage: 'پاسخ ناشناخته از سپهر دریافت شد.',
            );

        } catch (ProviderException $e) {
            return new LockFlightResult(
                status: false,
                lockId: false,
                rawResult: [],
                errorCode: $e->getProviderCode() ?: ('2001-'.$e->getCode()),
                errorMessage: $e->getMessage(),
            );
        }
    }

    public function getBookStatus(BookStatusRequest $request): BookStatusResult
    {
        // ─── 1. Credential resolve ───────────────────────────────────────────────
        $credential = $this->credentialRepo->getBySupplier($request->supplierId);

        if (! $credential) {
            throw CredentialNotFoundException::forBranch($request->branch);
        }

        // ─── 2. HTTP call ────────────────────────────────────────────────────────
        // BookGetStatus از Credential wrapper استفاده می‌کنه (SepehrConfig::usesCredentialWrapper)
        try {
            $raw = $this->httpClient->postWithTimeout($credential, 'BookGetStatus', [
                'YourLocalInventoryPnr' => $request->localInventoryPnr,
            ]);

            // ─── 3a. ErrorMessage از Sepehr (1504) ───────────────────────────────
            if (isset($raw['ErrorMessage'])) {
                return new BookStatusResult(
                    status: false,
                    statusId: false,
                    statusDesc: false,
                    localPnr: false,
                    failReason: false,
                    rawResult: $raw,
                    errorCode: '1504-'.($raw['ExceptionType'] ?? 'Exception'),
                    errorMessage: $raw['ErrorMessage'],
                );
            }

            // ─── 3b. StatusId != 1 → صادر نشده (1505) ────────────────────────────
            if (isset($raw['StatusId']) && (int) $raw['StatusId'] !== 1) {
                $failReason = $raw['FailReason'] ?? false;

                return new BookStatusResult(
                    status: false,
                    statusId: (int) $raw['StatusId'],
                    statusDesc: $raw['StatusDesc'] ?? false,
                    localPnr: $raw['LocalPnr'] ?? false,
                    failReason: $failReason,
                    rawResult: $raw,
                    errorCode: '1505-'.$raw['StatusId'],
                    errorMessage: $request->localInventoryPnr
                        .':'.($raw['StatusDesc'] ?? '')
                        .($failReason ? ' | '.$failReason : ''),
                );
            }

            // ─── 3c. StatusId == 1 → صادر شده ────────────────────────────────────
            if (isset($raw['StatusId']) && (int) $raw['StatusId'] === 1) {
                return new BookStatusResult(
                    status: true,
                    statusId: 1,
                    statusDesc: $raw['StatusDesc'] ?? false,
                    localPnr: $raw['LocalPnr'] ?? false,
                    failReason: false,
                    rawResult: $raw,
                );
            }

            // ─── 3d. پاسخ ناشناخته ───────────────────────────────────────────────
            return new BookStatusResult(
                status: false,
                statusId: false,
                statusDesc: false,
                localPnr: false,
                failReason: false,
                rawResult: $raw,
                errorCode: '1504',
                errorMessage: 'پاسخ ناشناخته از سپهر دریافت شد.',
            );

        } catch (ProviderException $e) {
            return new BookStatusResult(
                status: false,
                statusId: false,
                statusDesc: false,
                localPnr: false,
                failReason: false,
                rawResult: [],
                errorCode: $e->getProviderCode() ?: ('2002-'.$e->getCode()),
                errorMessage: $e->getMessage(),
            );
        }
    }

    // ─── Book ────────────────────────────────────────────────────────────────────

    public function book(BookFlightRequest $request): BookFlightResult
    {
        $credential = $this->credentialRepo->getBySupplier($request->supplierId);
        if (! $credential) {
            throw CredentialNotFoundException::forBranch($request->branch);
        }

        // Dispatch TemporaryReservation jobs قبل از Book
        if ($request->subData) {
            $url = $credential->url.SepehrConfig::getEndpoint('Book');
            $params = array_merge($credential->buildParams('Book'), $request->bookingData);

            TemporaryReservation::dispatch([
                'id' => $request->subData['lockId'], 'key' => 'url', 'value' => $url,
            ])->delay(now()->addMinutes(10))->onQueue('snailJob');

            TemporaryReservation::dispatch([
                'id' => $request->subData['lockId'], 'key' => 'reservation_request', 'value' => $params,
            ])->delay(now()->addMinutes(10))->onQueue('snailJob');
        }

        try {
            $raw = $this->httpClient->postWithTimeout($credential, 'Book', $request->bookingData);

            if ($request->subData) {
                TemporaryReservation::dispatch([
                    'id' => $request->subData['lockId'], 'key' => 'reservation', 'value' => $raw,
                ])->delay(now()->addMinutes(10))->onQueue('snailJob');
            }

            if (! isset($raw['LocalPnr']) && isset($raw['ErrorMessage'])) {
                return new BookFlightResult(
                    status: false,
                    booking: false,
                    rawResult: $raw,
                    errorCode: '1502-'.($raw['ExceptionType'] ?? ''),
                    errorMessage: $raw['ErrorMessage'],
                );
            }

            $booking = $this->flightMapper->mapBookingResponse($raw, $request->subData ?? []);

            return new BookFlightResult(status: true, booking: $booking, rawResult: $raw);

        } catch (ProviderException $e) {
            // fallback: BookGetStatus
            return $this->handleBookFailure($credential, $request, $e);
        }
    }

    // ─── Balance ─────────────────────────────────────────────────────────────────

    public function getBalance(int $supplierId): BalanceResult
    {
        $credential = $this->credentialRepo->getBySupplier($supplierId);
        if (! $credential) {
            throw CredentialNotFoundException::forBranch($supplierId);
        }

        $raw = $this->httpClient->post($credential, 'CurrentBalance', []);

        return new BalanceResult(
            status: true,
            remainedCredit: (int) $raw['RemainedCredit'],
            currencyCode: 'IRR',
            time: time(),
        );
    }

    // ─── ActiveRoutes ────────────────────────────────────────────────────────────

    public function syncActiveRoutes(int|string $branch): ActiveRoutesResult
    {
        $credentials = $this->resolveCredentials($branch);
        $unsubmitted = [];

        foreach ($credentials as $credential) {
            try {
                $raw = $this->httpClient->post($credential, 'GetActiveRoutes', []);

                if (isset($raw['ErrorMessage']) || ! isset($raw['ActiveRouteList'])) {
                    continue;
                }

                foreach ($raw['ActiveRouteList'] as $item) {
                    $origin = DB::table('airports')->select('id')->where('iata', $item['OriginIataCode'])->first();
                    $destination = DB::table('airports')->select('id')->where('iata', $item['DestinationIataCode'])->first();

                    $routeData = [
                        'colleague' => $credential->supplierObjectId,
                        'monday' => $item['Monday'],
                        'tuesday' => $item['Tuesday'],
                        'wednesday' => $item['Wednesday'],
                        'thursday' => $item['Thursday'],
                        'friday' => $item['Friday'],
                        'saturday' => $item['Saturday'],
                        'sunday' => $item['Sunday'],
                    ];

                    if ($origin && $destination) {
                        $this->activeRouteRepo->upsert(array_merge($routeData, [
                            'origin' => $origin->id,
                            'destination' => $destination->id,
                        ]));
                    } else {
                        $unsubmitted[] = array_merge($routeData, [
                            'origin' => $item['OriginIataCode'],
                            'destination' => $item['DestinationIataCode'],
                        ]);
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return new ActiveRoutesResult(status: true, unsubmittedItems: $unsubmitted);
    }

    // ─── Transaction ─────────────────────────────────────────────────────────────

    public function getTransaction(): string
    {
        return 0;
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    /**
     * @return SepehrCredential[]
     */
    private function resolveCredentials(int|string $branch, array|false $supplierIds = false): array
    {
        if ($supplierIds) {
            return array_filter(array_map(
                fn ($id) => $this->credentialRepo->getBySupplier($id),
                $supplierIds
            ));
        }

        $credentials = $this->credentialRepo->getByBranch($branch, 'sepehr');

        if (count($credentials) > 0) {
            return $credentials;
        }

        // fallback به hub
        $cleanBranch = (int) str_replace(['b2c-', 'b2b-'], '', (string) $branch);
        $baseOnline = $this->credentialRepo->getBranchBaseOnline($cleanBranch);

        if ($baseOnline === 1) {
            $hubCredentials = $this->credentialRepo->getHubCredentials('sepehr');
            if (count($hubCredentials) > 0) {
                $this->isHub = true;

                return $hubCredentials;
            }
        }

        throw CredentialNotFoundException::forBranch($branch);
    }

    private function tagFlights(array $flights, SepehrCredential $credential): array
    {
        return array_map(function ($item) use ($credential) {
            $item['SystemSupplier'] = $credential->supplierObjectId;
            $item['Nira'] = $credential->hasNira;

            return $item;
        }, $flights);
    }

    private function buildErrorResult(SepehrCredential $credential, mixed $errorData): array
    {
        return [
            'status' => false,
            'serviceId' => $credential->id,
            'serviceBranch' => $credential->branch,
            'isHub' => $credential->isHub,
            'supplier' => $credential->supplierObjectId,
            'data' => $errorData,
        ];
    }

    private function handleBookFailure(SepehrCredential $credential, BookFlightRequest $request, ProviderException $original): BookFlightResult
    {
        try {
            $statusRaw = $this->httpClient->postWithTimeout(
                $credential,
                'BookGetStatus',
                ['YourLocalInventoryPnr' => $request->bookingData['YourLocalInventoryPnr'] ?? ''],
            );

            if (isset($statusRaw['StatusId']) && $statusRaw['StatusId'] != 1) {
                return new BookFlightResult(
                    status: false,
                    booking: false,
                    rawResult: $statusRaw,
                    errorCode: '1505-'.$statusRaw['StatusId'],
                    errorMessage: ($request->bookingData['YourLocalInventoryPnr'] ?? '').':'.$statusRaw['StatusDesc'].':Last Error:'.$original->getMessage().(isset($statusRaw['FailReason']) ? ' | '.$statusRaw['FailReason'] : ''),
                );
            }

            if (! isset($statusRaw['LocalPnr']) && isset($statusRaw['ErrorMessage'])) {
                return new BookFlightResult(
                    status: false,
                    booking: false,
                    rawResult: $statusRaw,
                    errorCode: '1504-'.($statusRaw['ExceptionType'] ?? ''),
                    errorMessage: $statusRaw['ErrorMessage'],
                );
            }

        } catch (Throwable) {
        }

        return new BookFlightResult(
            status: false,
            booking: false,
            rawResult: [],
            errorCode: '2002-'.$original->getCode(),
            errorMessage: $original->getMessage().' : خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
        );
    }
}
