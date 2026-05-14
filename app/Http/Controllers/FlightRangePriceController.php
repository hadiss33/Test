<?php

namespace App\Http\Controllers;

use App\Models\AirlineActiveRoute;
use App\Models\FlightRangePrice;

class FlightRangePriceController extends Controller
{
    public function getTheRow()
    {
        AirlineActiveRoute::query()
            ->groupBy('airline_active_routes.origin', 'airline_active_routes.destination')
            ->select(
                'airline_active_routes.origin',
                'airline_active_routes.destination',
            )
            ->chunk(100, function ($results) {
                foreach ($results as $row) {
                    FlightRangePrice::updateOrCreate(
                        ['origin' => $row->origin, 'destination' => $row->destination],
                    );
                }
            });
    }

    public function getRangeOfPrices()
    {
        AirlineActiveRoute::query()
            ->join('flights', 'flights.airline_active_route_id', '=', 'airline_active_routes.id')
            ->select(
                'airline_active_routes.origin',
                'airline_active_routes.destination'
            )
            ->selectRaw('MIN(flights.min) as min, MAX(flights.max) as max')
            ->groupBy(
                'airline_active_routes.origin',
                'airline_active_routes.destination'
            )
            ->chunk(100, function ($results) {
                foreach ($results as $row) {
                    FlightRangePrice::updateOrCreate(
                        [
                            'origin' => $row->origin,
                            'destination' => $row->destination,
                        ],
                        [
                            'min' => $row->min,
                            'max' => $row->max,
                        ]
                    );
                }
            });

    }
}
