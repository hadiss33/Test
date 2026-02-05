<?php

namespace App\Services\FlightUpdaters;

use App\Models\AirlineActiveRoute;
use App\Models\Flight;
use App\Models\FlightBaggage;
use App\Models\FlightClass;
use App\Models\FlightDetail;
use App\Models\FlightFareBreakdown;
use App\Models\FlightRule;
use App\Models\FlightTax;
use App\Services\FlightProviders\FlightProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sepehr Flight Updater
 *
 * Uses 1-step process:
 * - GetCharterFlights - Returns complete flight data including:
 *   - Flight details (number, times, aircraft)
 *   - All classes with fares
 *   - Baggage info
 *   - Cancellation policies (rules)
 *
 * NO Jobs needed - all data comes in one API call!
 */
class RavisFlightUpdater implements FlightUpdaterInterface
{
    protected FlightProviderInterface $provider;

    protected ?string $iata;

    protected string $service;

    public function __construct(FlightProviderInterface $provider, ?string $iata, string $service = 'sepehr')
    {
        $this->provider = $provider;
        $this->iata = $iata;
        $this->service = $service;
    }

    public function updateByPeriod(int $period): array
    {
        return [];
    }
}