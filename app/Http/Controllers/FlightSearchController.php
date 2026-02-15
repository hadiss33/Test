<?php

namespace App\Http\Controllers;

use App\Enums\SnapTrip\CabinClassCode;
use App\Enums\SnapTrip\DirectionInd;
use App\Http\Resources\FlightResource;
use App\Http\Resources\SnapFlightResource;
use App\Models\Flight;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FlightSearchController extends Controller
{
    protected $repository;

    public function __construct(FlightServiceRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }


    public function getAdvancedFlights(Request $request)
    {
        if (! $request->has('options')) {
            $request->merge(['options' => ['FareBreakdown', 'Baggage', 'Tax', 'Rule']]);
        } elseif (is_string($request->input('options'))) {
            $cleanOptions = trim($request->input('options'), '[]');
            $request->merge(['options' => ! empty($cleanOptions) ? array_map('trim', explode(',', $cleanOptions)) : []]);
        }

        $validator = Validator::make($request->all(), [
            'datetime_start' => 'required|date',
            'datetime_end' => 'nullable|date|after_or_equal:datetime_start',
            'origin' => 'nullable|string|size:3',
            'destination' => 'nullable|string|size:3',
            'airline' => 'nullable|string',
            'return' => 'nullable|boolean',
            'options' => 'nullable|array',
            'service' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $relations = [
            'route.applicationInterface',
            'details',
            'classes.rules',
            'classes.fareBaggage',
            'classes.taxes',
            'classes.fareBreakdown',
        ];

        $query = Flight::query()->with(array_unique($relations));

        if ($request->filled('datetime_end')) {
            $query->whereDate('departure_datetime', '>=', $request->datetime_start)
                ->whereDate('departure_datetime', '<=', $request->datetime_end);
        } else {
            $query->whereDate('departure_datetime', $request->datetime_start);
        }

        $origin = $request->origin;
        $dest = $request->destination;
        $isReturn = $request->boolean('return');

        if ($origin || $dest) {
            $query->whereHas('route', function ($q) use ($origin, $dest, $isReturn) {
                if ($isReturn && $origin && $dest) {
                    $q->where(function ($subQ) use ($origin, $dest) {
                        $subQ->where('origin', $origin)
                            ->where('destination', $dest);
                    })->orWhere(function ($subQ) use ($origin, $dest) {
                        $subQ->where('origin', $dest)
                            ->where('destination', $origin);
                    });
                } else {
                    if ($origin) {
                        $q->where('origin', $origin);
                    }
                    if ($dest) {
                        $q->where('destination', $dest);
                    }
                }
            });
        }

        $query->filter($request->only(['airline', 'service']));

        $dbFlights = $query->orderBy('departure_datetime')->get();
        $dbData = FlightResource::collection($dbFlights)->resolve();

        $externalData = [];
        // if (is_null($request->input('service')) || $request->input('service') === 'snapptrip_flight') {
        //     try {
        //         $rawResponse = $this->snapFlight($request);
        //         if (! empty($rawResponse['pricedItineraries'])) {
        //             $externalData = SnapFlightResource::collection($rawResponse['pricedItineraries'])->resolve();
        //         }
        //     } catch (\Exception $e) {
        //         Log::error('SnappTrip Error: '.$e->getMessage());
        //     }
        // }

        $data = array_merge($dbData ?? [], $externalData ?? []);

        return response()->json([
            'status' => 'success',
            'meta' => [
                'total_count' => count($data),
                'db_source_count' => $dbFlights->count(),
                'external_source_count' => count($externalData),
                'return_search' => $isReturn,
            ],
            'data' => $data,
        ]);
    }

    public function snapFlight(Request $request): array
    {
        $service = $request->input('service') ?: 'snapptrip_flight';
        $interface = $this->repository->getService($service);

        $endpoint = rtrim($interface['url'], '/').'/api/v1/search';
        $body = $this->buildSnapSearchRequest($request, CabinClassCode::Default);

        Log::info('Sending request to SnappTrip', ['endpoint' => $endpoint, 'body' => $body]);

        $response = Http::timeout(120)->connectTimeout(120)
            ->post($endpoint, $body)
            ->throw()
            ->json();

        Log::info('Received response from SnappTrip', ['response_count' => count($response)]);
        Log::info(' SnappTrip', ['response' => $response]);

        return $response;
    }

    protected function buildSnapSearchRequest(Request $request, CabinClassCode $cabin): array
    {
        return [
            'searchRequest' => [
                'adult' => 1,
                'child' => 0,
                'infant' => 0,
                'isDomestic' => true,
                'originDestinationInformations' => [[
                    'departureDateTime' => Carbon::parse($request->datetime_start)->format('Y-m-d\TH:i:s'),
                    'originLocationCode' => $request->origin,
                    'destinationLocationCode' => $request->destination,
                    'originType' => 2,
                    'destinationType' => 2,
                ]],
                'travelPreference' => [
                    'airTripType' => DirectionInd::OneWay->value,
                    'cabinType' => 100,
                    'maxStopsQuantity' => 0,
                ],
            ],
        ];
    }

    public function snapTripFlight(Request $request)
    {
        Log::info('--- Mock Search Request Received ---');
        Log::info('Payload:', $request->all());

        $validator = Validator::make($request->all(), [
            'searchRequest' => 'required|array',
            'searchRequest.adult' => 'required|integer|min:1',
            'searchRequest.child' => 'integer|min:0',
            'searchRequest.infant' => 'integer|min:0',
            'searchRequest.isDomestic' => 'boolean',

            'searchRequest.originDestinationInformations' => 'required|array|min:1',
            'searchRequest.originDestinationInformations.*.departureDateTime' => 'required|date',
            'searchRequest.originDestinationInformations.*.originLocationCode' => 'required|string|size:3',
            'searchRequest.originDestinationInformations.*.destinationLocationCode' => 'required|string|size:3',

            'searchRequest.travelPreference' => 'required|array',
            'searchRequest.travelPreference.cabinType' => 'required|integer',
            'searchRequest.travelPreference.airTripType' => 'required|integer',
        ]);

        if ($validator->fails()) {
            Log::warning('Validation Failed:', $validator->errors()->toArray());

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $validator->errors()->first(),
                ],
            ], 400);
        }

        $jsonPath = storage_path('app/public/flghts.json');

        if (! file_exists($jsonPath)) {
            Log::critical("Mock Data File Not Found at: $jsonPath");

            return response()->json([
                'success' => false,
                'error' => ['message' => 'Internal Server Error: Mock data missing'],
            ], 500);
        }

        $jsonContent = file_get_contents($jsonPath);
        $data = json_decode($jsonContent, true);

        if (! $data || ! isset($data['pricedItineraries'])) {
            Log::error("Invalid JSON format or missing 'pricedItineraries' key.");

            return response()->json([
                'success' => false,
                'error' => ['message' => 'Internal Server Error: Invalid Mock Data'],
            ], 500);
        }

        // 👇 بدون فیلتر، کل دیتا به صورت Collection
        $pricedItineraries = collect($data['pricedItineraries'])->values();

        Log::info('Total flights count: '.$pricedItineraries->count());

        return response()->json([
            'success' => true,
            'searchId' => rand(100000, 999999),
            'pricedItineraries' => $pricedItineraries,
            'error' => null,
        ]);
    }

    public function snapTripFlightBook(Request $request)
    {
        Log::info('--- Mock Book Request ---', $request->all());

        $validator = Validator::make($request->all(), [
            'fareSourceCode' => 'required|string',
            'phoneNumber' => 'required|string',
            'email' => 'required|email',
            'passengers' => 'required|array|min:1',

            'passengers.*.firstName' => 'required|string',
            'passengers.*.lastName' => 'required|string',
            'passengers.*.gender' => 'required|in:MALE,FEMALE',
            'passengers.*.birthday' => 'required|date',
            'passengers.*.passengerType' => 'required|in:ADULT,CHILD,INFANT',
            'passengers.*.nationalityCode' => 'required|string|size:2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $validator->errors()->first(),
                ],
            ], 400);
        }

        $flightsPath = storage_path('app/public/flghts.json');
        $flights = collect(json_decode(file_get_contents($flightsPath), true)['pricedItineraries']);

        $flight = $flights->firstWhere('fareSourceCode', $request->fareSourceCode);

        if (! $flight) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SOLUTION_EXPIRED',
                    'message' => 'Solution is no longer available',
                ],
            ], 410);
        }

        $bookId = 'BOOK-'.md5($request->fareSourceCode.now()->timestamp);

        $totalFare = $flight['airItineraryPricingInfo']['itinTotalFare']['totalFare'];
        $currency = $flight['airItineraryPricingInfo']['itinTotalFare']['currency'];

        $bookingPath = storage_path('app/public/bookings.json');
        $bookings = file_exists($bookingPath)
            ? json_decode(file_get_contents($bookingPath), true)
            : [];

        $bookings[$bookId] = [
            'fareSourceCode' => $request->fareSourceCode,
            'status' => 'BOOKED',
            'paymentAmount' => $totalFare,
            'currency' => $currency,
            'passengers' => $request->passengers,
            'createdAt' => now()->toDateTimeString(),
        ];

        file_put_contents($bookingPath, json_encode($bookings, JSON_PRETTY_PRINT));

        return response()->json([
            'trackingCode' => 'TRK-'.rand(100000, 999999),
            'checkoutUrl' => $bookId,
            'bookId' => $bookId,
            'paymentCurrency' => $currency,
            'paymentAmount' => $totalFare,
        ]);
    }

    public function snapTripFlightIssue(Request $request)
    {
        // 1. بررسی دقیق ورودی طبق داکیومنت
        $validator = Validator::make($request->all(), [
            'bookId' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'FAILED', // یا ساختار خطای استاندارد اسنپ
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => $validator->errors()->first(),
                ],
            ], 400);
        }

        // 2. لاجیک نمونه (Mock Logic)
        // ما اینجا فرض می‌کنیم هر bookId که بیاید معتبر است تا تست ERP پاس شود.
        // اعداد رندوم تولید می‌کنیم تا فاکتور ERP واقعی به نظر برسد.

        $mockPnr = 'MOCK-'.strtoupper(substr(md5(microtime()), 0, 5));
        $mockTicketNumber = '999-'.rand(1000000000, 9999999999);

        // (اختیاری) اگر می‌خواهی وضعیت فایل را هم آپدیت کنی که بعداً در inquiry ببینی:
        $bookingPath = storage_path('app/public/bookings.json');
        if (file_exists($bookingPath)) {
            $bookings = json_decode(file_get_contents($bookingPath), true);
            if (isset($bookings[$request->bookId])) {
                $bookings[$request->bookId]['status'] = 'ISSUED'; // وضعیت داخلی ماک
                $bookings[$request->bookId]['pnr'] = $mockPnr;
                $bookings[$request->bookId]['ticketNumber'] = $mockTicketNumber;
                file_put_contents($bookingPath, json_encode($bookings, JSON_PRETTY_PRINT));
            }
        }

        // 3. خروجی دقیق طبق داکیومنت و نیاز ERP
        // نکته مهم: کلمه کلیدی SUCCEED باید دقیقاً همین باشد.
        return response()->json([
            'status' => 'SUCCEED',
            // اسنپ در داکیومنت اصلی Issue، معمولاً فقط status برمی‌گرداند،
            // اما برخی پرووایدرها در پاسخ Issue اطلاعات بلیط را هم می‌دهند.
            // ما اینجا اطلاعات را می‌فرستیم که اگر ERP نیاز داشت استفاده کند.
            'pnr' => $mockPnr,
            'ticketNumber' => $mockTicketNumber,
            'trackingCode' => $request->bookId,
        ], 200);
    }
}
