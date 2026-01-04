<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Flight;
use Illuminate\Support\Facades\Validator;

class FlightSearchController extends Controller
{

    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'origin' => 'required|string|size:3',
            'destination' => 'required|string|size:3',
            'date' => 'required|date|after_or_equal:today',
            'airline' => 'nullable|string',
            'class' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = Flight::with(['route', 'classes.fareBreakdown', 'details'])
            ->whereHas('route', function($q) use ($request) {
                $q->where('origin', $request->origin)
                  ->where('destination', $request->destination);
                
                if ($request->airline) {
                    $q->where('iata', $request->airline);
                }
            })
            ->whereDate('departure_datetime', $request->date)
            ->upcoming()
            ->orderBy('departure_datetime');

        if ($request->class) {
            $query->whereHas('classes', function($q) use ($request) {
                $q->where('class_code', $request->class)
                  ->available();
            });
        }

        $flights = $query->get()->map(function($flight) use ($request) {
            $classes = $flight->classes;
            
            if ($request->class) {
                $classes = $classes->where('class_code', $request->class);
            }
            
            return [
                'id' => $flight->id,
                'flight_number' => $flight->flight_number,
                'airline' => $flight->route->iata,
                'origin' => $flight->route->origin,
                'destination' => $flight->route->destination,
                'departure_datetime' => $flight->departure_datetime->toIso8601String(),
                'arrival_datetime' => $flight->details?->arrival_datetime?->toIso8601String(),
                'duration_minutes' => $flight->details?->flight_duration,
                'aircraft_type' => $flight->aircraft_type,
                'has_transit' => $flight->details?->has_transit ?? false,
                'classes' => $classes->map(function($class) {
                    return [
                        'code' => $class->class_code,
                        'status' => $class->status,
                        'available_seats' => $class->available_seats,
                        'prices' => [
                            'adult' => (float) $class->price_adult,
                            'child' => (float) $class->price_child,
                            'infant' => (float) $class->price_infant,
                        ],
                        'fare_breakdown' => $class->fareBreakdown->keyBy('passenger_type')->map(function($fare) {
                            return [
                                'base_fare' => (float) $fare->base_fare,
                                'taxes' => [
                                    'i6' => (float) $fare->tax_i6,
                                    'v0' => (float) $fare->tax_v0,
                                    'hl' => (float) $fare->tax_hl,
                                    'lp' => (float) $fare->tax_lp,
                                ],
                                'total' => (float) $fare->total_price,
                            ];
                        }),
                    ];
                }),
            ];
        });

        return response()->json([
            'status' => 'success',
            'count' => $flights->count(),
            'data' => $flights
        ]);
    }

    public function show($id)
    {
        $flight = Flight::with(['route', 'classes.fareBreakdown', 'details'])
            ->find($id);

        if (!$flight) {
            return response()->json(['error' => 'Flight not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $flight->id,
                'flight_number' => $flight->flight_number,
                'airline' => [
                    'iata' => $flight->route->iata,
                    'name' => $this->getAirlineName($flight->route->iata),
                ],
                'route' => [
                    'origin' => $flight->route->origin,
                    'destination' => $flight->route->destination,
                ],
                'schedule' => [
                    'departure' => $flight->departure_datetime->toIso8601String(),
                    'arrival' => $flight->details?->arrival_datetime?->toIso8601String(),
                    'duration_minutes' => $flight->details?->flight_duration,
                ],
                'aircraft' => [
                    'type' => $flight->aircraft_type,
                ],
                'transit' => [
                    'has_transit' => $flight->details?->has_transit ?? false,
                    'city' => $flight->details?->transit_city,
                ],
                'FlightBaggage' => [
                    'weight' => $flight->details?->baggage_weight,
                    'pieces' => $flight->details?->baggage_pieces,
                ],
                'refund_rules' => $flight->details?->refund_rules,
                'classes' => $flight->classes->map(function($class) {
                    return [
                        'code' => $class->class_code,
                        'status' => $class->status,
                        'available_seats' => $class->available_seats,
                        'is_available' => $class->isAvailable(),
                        'prices' => [
                            'adult' => (float) $class->price_adult,
                            'child' => (float) $class->price_child,
                            'infant' => (float) $class->price_infant,
                        ],
                        'fare_breakdown' => $class->fareBreakdown->keyBy('passenger_type'),
                    ];
                }),
                'last_updated' => $flight->last_updated_at->toIso8601String(),
            ]
        ]);
    }

    protected function getAirlineName(string $iata): string
    {
        $airlines = [
            'Y9' => 'کیش ایر', 'EP' => 'ایران ایر', 'FP' => 'فلای پرشیا',
            'HH' => 'تابان', 'IV' => 'کاسپین', 'I3' => 'آتا',
            'NV' => 'کارون', 'PA' => 'پارس ایر', 'ZV' => 'زاگرس',
        ];
        return $airlines[$iata] ?? $iata;
    }
}