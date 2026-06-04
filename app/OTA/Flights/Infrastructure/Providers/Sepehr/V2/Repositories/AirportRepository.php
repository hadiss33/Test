<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Repositories;

use App\Services\OTA\Flights\Domain\Repositories\DetailsInterface;
use Illuminate\Support\Facades\DB;

class AirportRepository implements DetailsInterface
{
    public function getAirportId(string $IataCode): int|null
    {
        $airport = DB::table('airports')->select('id')->where('iata', $IataCode)->first();
        return $airport ? (int)$airport->id : null;
    }


}