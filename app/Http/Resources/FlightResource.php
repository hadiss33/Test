<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flight_number' => $this->flight_number,
            'departure_datetime' => $this->departure_datetime?->toIso8601String(),
            
            'route' => $this->whenLoaded('route', function () {
                return [
                    'origin' => $this->route->origin,
                    'destination' => $this->route->destination,
                    'airline' => $this->route->iata,
                ];
            }),

            'details' => $this->whenLoaded('details', function () {
                return [
                    'arrival_datetime' => $this->details->arrival_datetime?->toIso8601String(),
                    'duration_minutes' => $this->details->flight_duration,
                    'aircraft_type' => $this->details->aircraft_type_code,
                ];
            }),

            'classes' => $this->whenLoaded('classes', function () use ($request) {
                $options = $request->input('options', []);

                return $this->classes->map(function ($class) use ($options) {
                    
                    $classData = [
                        'code' => $class->class_code,
                        'status' => $class->status,
                        'available_seats' => $class->available_seats,
                    ];

                    if ($class->relationLoaded('fareBreakdown')) {
                        $classData['fare_breakdown'] = $class->fare_breakdown_formatted;
                    }

                    if ($class->relationLoaded('fareBaggage')) {
                        $classData['baggage'] = $class->baggage_formatted;
                    }

                    if ($class->relationLoaded('rules')) {
                        $classData['rules'] = $class->rules;
                    }


                    $showTax = in_array('Tax', $options);
                    $showDetails = in_array('TaxDetails', $options);

                    if ($class->relationLoaded('taxes') && ($showTax || $showDetails)) {
                        
                        $summary = $class->tax_summary; 
                        
                        $formattedTaxes = [];
                        foreach ($summary as $type => $info) {
                            $item = ['passenger_type' => $type];

                            if ($showDetails) {
                                $item['details'] = $info['details'];
                            }
                            
                            $item['total_amount'] = $info['total_amount'];
                            
                            $formattedTaxes[] = $item;
                        }
                        
                        $classData['taxes'] = $formattedTaxes;
                    }

                    return $classData;
                });
            }),
        ];
    }
}