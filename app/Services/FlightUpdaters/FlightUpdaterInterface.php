<?php

namespace App\Services\FlightUpdaters;

use App\Services\FlightProviders\FlightProviderInterface;

interface FlightUpdaterInterface
{
    /**
     * Constructor
     * 
     * @param FlightProviderInterface $provider
     * @param string|null $iata IATA code for airlines (null for aggregators)
     * @param string $service Service name
     */
    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service);

    /**
     * Update flights by period
     * 
     * @param int $period Days ahead (3, 7, 30, 60, 90, 120)
     * @return array Statistics
     */
    public function updateByPeriod(int $period): array;
}