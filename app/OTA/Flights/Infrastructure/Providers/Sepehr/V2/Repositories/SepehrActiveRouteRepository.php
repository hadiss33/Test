<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Repositories;

use App\Services\OTA\Flights\Domain\Repositories\ActiveRouteRepositoryInterface;
use Illuminate\Support\Facades\DB;

class SepehrActiveRouteRepository implements ActiveRouteRepositoryInterface
{
    public function findRoute(int $supplierId, int $originId, int $destinationId, string $dayOfWeek): ?object
    {
        return DB::table('flight_active_route')
            ->select('id')
            ->where('colleague', $supplierId)
            ->where('origin', $originId)
            ->where('destination', $destinationId)
            ->where($dayOfWeek, true)
            ->first();
    }

    public function upsert(array $data): void
    {
        $existing = DB::table('flight_active_route')
            ->where('colleague', $data['colleague'])
            ->where('origin', $data['origin'])
            ->where('destination', $data['destination'])
            ->first();

        if (is_null($existing)) {
            DB::table('flight_active_route')->insert($data);
        } else {
            DB::table('flight_active_route')->where('id', $existing->id)->update($data);
        }
    }
}