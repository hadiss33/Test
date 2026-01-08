<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class FlightResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            "charter_id" => $this->charter_id ?? false,
            "serial" => $this->serial_number ?? false,
            "supplier" => $this->supplier_id ?? false,
            "id" => $this->unique_id ?? false,
            "plan" => $this->plan ?? false,
            
            "details" => [
                "airline" => [
                    "iata" => $this->airline?->iata_code ?? "", 
                    "icao" => $this->airline?->icao_code ?? "",
                    "logo" => $this->airline?->logo_url ?? "",
                    "title" => [
                        "en" => $this->airline?->name_en ?? "",
                        "fa" => $this->airline?->name_fa ?? "",
                    ]
                ],
                "origin" => [
                    "iata" => $this->origin?->code ?? "",
                    "terminal" => $this->origin_terminal ?: false
                ],
                "destination" => [
                    "iata" => $this->destination?->code ?? "",
                    "terminal" => $this->destination_terminal ?: false
                ],
                "aircraft" => [
                    "iata" => $this->aircraft?->iata ?? "",
                    "icao" => $this->aircraft?->icao ?? "",
                    "title" => [
                        "en" => $this->aircraft?->name_en ?? "",
                        "fa" => $this->aircraft?->name_fa ?? "",
                    ]
                ],
                "flight_number" => $this->flight_number,
                "steps" => $this->steps ?: false,
                "duration" => $this->duration ?: false,
                
                "datetime" => $this->formatDate($this->departure_time),
                "arrival_datetime" => $this->formatDate($this->arrival_time) ?: false,
            ],

            "items" => $this->flightItems ? $this->flightItems->map(function ($item) {
                return $this->formatItem($item);
            }) : [],

            "description" => [
                "public" => $this->public_description ?: false,
                "financial" => $this->financial_description ?: false,
            ]
        ];
    }

    public function with($request)
    {
        return [
            'meta' => [
                'timestamp' => now()->toDateTimeString(),
            ],
        ];
    }

    private function formatDate($date)
    {
        if (!$date) return null;
        try {
            return \Carbon\Carbon::parse($date)->format('Y-m-d H:i');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function formatItem($item)
    {
        return [
            "item_id" => $item->id,
            "title" => $item->title,
            "reservable" => (bool) $item->is_reservable,
            "statistics" => [
                "capacity" => $item->capacity,
                "waiting" => $item->waiting_list ?? 0,
            ],
            "max_purchase" => $item->max_purchase,
            "rules" => $item->rules,
            "services" => $item->services ?: false,
            
            "baggage" => [
                "trunk" => $this->formatBaggage($item->baggage_trunk),
                "hand" => $this->formatBaggage($item->baggage_hand),
            ],

            "financial" => [
                "adult" => $this->formatFinancial($item->prices?->where('type', 'adult')->first()),
                "child" => $this->formatFinancial($item->prices?->where('type', 'child')->first()),
                "infant" => $this->formatFinancial($item->prices?->where('type', 'infant')->first()),
            ],
        ];
    }

    private function formatBaggage($baggageData)
    {
        if (!$baggageData) return null;
        
        $data = (object) $baggageData;

        return [
            "adult" => ["number" => $data->adult_count ?? 1, "weight" => $data->adult_weight ?? 20],
            "child" => ["number" => $data->child_count ?? 1, "weight" => $data->child_weight ?? 20],
            "infant" => ["number" => $data->infant_count ?? 0, "weight" => $data->infant_weight ?? 0]
        ];
    }

    private function formatFinancial($priceData)
    {
        if (!$priceData) return null;

        return [
            "base_fare" => $priceData->base_fare,
            "taxes" => $priceData->tax_amount ?: false,
            "total_fare" => $priceData->total_amount,
            "payable" => $priceData->payable_amount,
            "markups" => $priceData->markup ?: false,
            "commissions" => $priceData->commission ?: false,
            "citizenship" => $priceData->citizenship ?: false,
        ];
    }
}