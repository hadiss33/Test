<?php

namespace App\Services\OTA\Flights\Domain\Entities;

use App\Services\OTA\Flights\Domain\ValueObjects\Baggage;
use App\Services\OTA\Flights\Domain\ValueObjects\BookingPolicy;
use App\Services\OTA\Flights\Domain\ValueObjects\Financial;
use App\Services\OTA\Flights\Domain\ValueObjects\FlightRemarks;

final readonly class FlightClass
{
    public function __construct(
        public bool                 $flightStatus,
        public bool                 $reservable,
        public string               $status,           // 'reservable' | 'full' | 'canceled'
        public string|false         $cancelationPolicy,
        public BookingPolicy|false  $bookingPolicy,
        public array                $supplier,
        public array                $systemSupplier,
        public string               $flightId,         // BookingCode
        public string|false         $fareName,
        public array                $cabinType,        // ['iata' => 'Y', 'title' => [...]]
        public int|false            $availableSeat,
        public bool                 $rules,
        public Financial            $financial,
        public array                $baseData,
        public Baggage              $baggage,
        public FlightRemarks|false  $inboundRemarks,
        public BookingPolicy|false  $outboundPolicy,
    ) {}
}