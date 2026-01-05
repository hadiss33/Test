<?php

namespace App\Jobs;

use App\Models\FlightClass;
use App\Services\FlightProviders\NiraProvider;
use App\Services\FlightUpdateService;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;


class FetchFlightFareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $flightClass;

    public function __construct(FlightClass $flightClass)
    {
        $this->flightClass = $flightClass;
    }

    public function handle(FlightServiceRepositoryInterface $repository)
    {
        try {
            $this->flightClass->load(['flight.route']);
            $flight = $this->flightClass->flight;
            $route = $flight->route;

            if (!$flight || !$route) {
                Log::warning("Flight or Route not found for Class ID: {$this->flightClass->id}");
                return;
            }


            $config = $repository->getServiceByCode('nira', $route->iata);

            if (empty($config)) {
                Log::error("Config not found for IATA: {$route->iata}");
                return;
            }

            $provider = new NiraProvider($config);
            $service = new FlightUpdateService($provider, $route->iata, 'nira');


            $classData = ['FlightClass' => $this->flightClass->class_code];

            $service->getFare(
                $this->flightClass,
                $route,
                $classData,
                $flight->departure_datetime,
                $flight
            );

            Log::info("Fare details fetched for Class: {$this->flightClass->id} ({$this->flightClass->class_code})");

        } catch (\Exception $e) {
            Log::error("FetchFlightFareJob Failed: " . $e->getMessage());

        }
    }
}