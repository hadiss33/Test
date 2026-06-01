<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2;

use App\Services\OTA\Flights\Domain\Repositories\V2\FlightDictionaryRepositoryInterface;
use Carbon\Carbon;

final class ApiMapper
{
    public function __construct(
        private readonly FlightDictionaryRepositoryInterface $dictionary,
        private readonly bool $details = true,
        private readonly string|int|false $branch = false
    ) {}

    /**
     * متد اصلی برای پردازش کل ریسپانس سرچ سپهر (V17)
     */
    public function mapSearchResponse(array $response, array $supplierInfo): array
    {
        // تله دیباگ ۱: بررسی ورود به متد و ساختار اولیه
        // dd('وارد مپر شد!', $response['ItineraryList'] ?? 'ItineraryList وجود ندارد!');

        $mappedFlights = [];

        if (!empty($response['ItineraryList'])) {
            foreach ($response['ItineraryList'] as $itineraryIndex => $itinerary) {
                if (!empty($itinerary['FlightSegmentList'])) {
                    foreach ($itinerary['FlightSegmentList'] as $segmentIndex => $segment) {
                        try {
                            $mappedFlights[] = $this->mapFlight($segment, $supplierInfo);
                        } catch (\Throwable $e) {
                            // تله دیباگ ۲: شکار ارور به صورت دقیق!
                            dd([
                                'message' => 'ارور در پردازش سگمنت!',
                                'error' => $e->getMessage(),
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'itinerary_index' => $itineraryIndex,
                                'segment_index' => $segmentIndex,
                                'raw_segment_data' => $segment
                            ]);
                        }
                    }
                }
            }
        }

        return $mappedFlights;
    }

    /**
     * مپ کردن یک آیتم پرواز به ساختار نهایی و استاندارد
     */
    private function mapFlight(array $data, array $supplierInfo): array
    {
        // تله دیباگ ۳: اگر ارور مربوط به کربن باشه اینجا مشخص میشه
        if (!isset($data['DepartureDateTime'])) {
            dd('فیلد DepartureDateTime در دیتای سپهر وجود ندارد!', $data);
        }

        $departureTime = Carbon::parse($data['DepartureDateTime']);
        $arrivalTime = !empty($data['ArrivalDateTime']) ? Carbon::parse($data['ArrivalDateTime']) : false;
        $duration = $arrivalTime ? $departureTime->diffInMinutes($arrivalTime) : (int)($data['Duration'] ?? 0);

        $isCharter = $data['IsFlightOwnedBySupplier'] ?? false;
        $flightType = $isCharter ? 'Charter' : 'System';

        // تله دیباگ ۴: بررسی ساختار Origin و Destination
        if (!isset($data['Origin'])) {
            dd('فیلد Origin کلا در ریسپانس نیست!', $data);
        }

        $originCode = is_array($data['Origin']) ? ($data['Origin']['IataCode'] ?? $data['Origin']['Code'] ?? '') : $data['Origin'];
        $destinationCode = is_array($data['Destination']) ? ($data['Destination']['IataCode'] ?? $data['Destination']['Code'] ?? '') : $data['Destination'];

        return [
            "Service" => "sepehr",
            "ServiceId" => $supplierInfo['id'] ?? "90",
            "ServiceBranch" => $this->branch ?: "2",
            "DisplayableService" => "sepehr",
            "Verified" => false,
            "FlightType" => $flightType,
            "FlightRoute" => "Internal", 
            "FlightNumber" => (string)($data['FlightNumber'] ?? ''),
            
            "Origin" => [
                "Iata" => $this->dictionary->getAirport($originCode, $this->details),
                "Terminal" => false
            ],
            "Destination" => [
                "Iata" => $this->dictionary->getAirport($destinationCode, $this->details),
                "Terminal" => false
            ],
            
            "DepartureDateTime" => $data['DepartureDateTime'] ?? null,
            "ArrivalDateTime" => $data['ArrivalDateTime'] ?? false,
            "Duration" => $duration,
            
            "Aircraft" => $this->dictionary->getAircraft($data['Aircraft'] ?? '', $this->details),
            "Airline" => $this->dictionary->getAirline($data['Airline'] ?? '', $this->details),
            
            "Remarks" => [
                "AirlineSupplier" => false,
                "TourRequirement" => false,
                "OneWayRequirement" => false,
                "RoundtripRequirement" => false,
                "PhoneRequirement" => false,
                "Description" => $data['Remarks'] ?? false,
                "Special" => false,
                "Warranty" => false
            ],
            
            "ReturningFlight" => false,
            "Steps" => false,
            
            "Classes" => isset($data['FlightClass']) ? [$this->mapSingleClass($data['FlightClass'], $supplierInfo)] : []
        ];
    }

    /**
     * مپ کردن اطلاعات مالی و کلاس پروازی
     */
    private function mapSingleClass(array $classData, array $supplierInfo): array
    {
        return [
            "FlightStatus" => true,
            "Reservable" => true,
            "Status" => "reservable",
            "CancelationPolicy" => !empty($classData['CancelationPolicyList']),
            "BookingPolicy" => false,
            
            "Supplier" => [
                "id" => $supplierInfo['id'] ?? "2",
                "title" => $supplierInfo['title'] ?? "Supplier 2"
            ],
            "SystemSupplier" => $this->dictionary->getSupplier($supplierInfo['id'] ?? 2, $this->branch),
            
            "FlightId" => $classData['BookingCode'] ?? '',
            "FareName" => $classData['FareName'] ?? '',
            
            "CabinType" => [
                "iata" => $classData['BookingCode'] ?? "Y",
                "title" => [
                    "fa" => $this->getCabinTitleFa($classData['CabinType'] ?? "Economy"),
                    "en" => $this->getCabinTitleEn($classData['CabinType'] ?? "Economy")
                ]
            ],
            
            "AvailableSeat" => (int)($classData['AvailableSeat'] ?? 0),
            "Rules" => false, 
            
            "Financial" => $this->mapFinancials($classData),
            
            "BaseData" => [
                "Supplier" => [
                    "Supplier" => [
                        "id" => $supplierInfo['id'] ?? "2",
                        "title" => $supplierInfo['title'] ?? "Supplier"
                    ],
                    "SystemSupplier" => $this->dictionary->getSupplier($supplierInfo['id'] ?? 2, $this->branch)
                ],
                "Financial" => $this->mapFinancials($classData)
            ],
            
            "Baggage" => $this->mapBaggage($classData),
            
            "Remarks" => [
                "Inbound" => [
                    "Description" => false,
                    "Special" => false,
                    "Warranty" => false
                ],
                "Outbound" => false
            ]
        ];
    }

    /**
     * ساختاردهی فیلدهای مالی
     */
    private function mapFinancials(array $classData): array
    {
        $mapFare = function ($fareData) {
            if (!$fareData) return [];
            
            $payable = (float)($fareData['Payable'] ?? $fareData['TotalFare'] ?? 0);
            return [
                "BaseFare" => (float)($fareData['BaseFare'] ?? 0),
                "Tax" => (float)($fareData['Tax'] ?? 0),
                "Markup" => (float)($fareData['Markup'] ?? 0),
                "TotalFare" => (float)($fareData['TotalFare'] ?? 0),
                "Payable" => $payable,
                "Commission" => [
                    "Percentage" => 0,
                    "Final" => (float)($fareData['Commission'] ?? 0),
                    "Price" => 0
                ]
            ];
        };

        return [
            "PriceAdditions" => ["Citizens" => 0],
            "CommissionPaid" => [
                "Percentage" => false,
                "Transaction" => "0",
                "MembershipRight" => false
            ],
            "Adult" => $mapFare($classData['AdultFare'] ?? []),
            "Child" => $mapFare($classData['ChildFare'] ?? []),
            "Infant" => $mapFare($classData['InfantFare'] ?? []),
            "RoundtripFare" => false
        ];
    }

    /**
     * استخراج بار مسافر (بر اساس دیتای V17)
     */
    private function mapBaggage(array $classData): array
    {
        $getBaggageInfo = function ($baggageData) {
            $weight = 20;
            if (isset($baggageData[0]['Value'])) {
                $weight = (int) filter_var($baggageData[0]['Value'], FILTER_SANITIZE_NUMBER_INT);
            }
            return [
                "Trunk" => ["Number" => 1, "TotalWeight" => $weight],
                "Hand" => ["Number" => 1, "TotalWeight" => 7]
            ];
        };

        return [
            "Adult" => $getBaggageInfo($classData['AdultFreeBaggage'] ?? []),
            "Child" => $getBaggageInfo($classData['ChildFreeBaggage'] ?? []),
            "Infant" => [
                "Trunk" => ["Number" => 0, "TotalWeight" => 0],
                "Hand" => ["Number" => 0, "TotalWeight" => 0]
            ]
        ];
    }

    private function getCabinTitleFa(string $type): string
    {
        return match (strtolower($type)) {
            'economy' => 'اکونومی',
            'business' => 'بیزینس',
            'first' => 'فرست کلاس',
            'premium' => 'اکونومی پلاس',
            default => 'اکونومی'
        };
    }

    private function getCabinTitleEn(string $type): string
    {
        return match (strtolower($type)) {
            'economy' => 'Economy/Coach',
            'business' => 'Business',
            'first' => 'First Class',
            'premium' => 'EconomyPlus',
            default => 'Economy/Coach'
        };
    }
}