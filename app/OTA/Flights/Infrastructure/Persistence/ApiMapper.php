<?php

namespace App\Services\OTA\Flights\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * مسئول resolve کردن airline، aircraft، airport از DB/Redis.
 * این داده‌ها بین همه providerها مشترک هستند.
 */
class ApiMapper
{
    public function getAirline(string $iataOrIcao): array
    {
        $row = DB::table('airlines')
            ->select('id')
            ->where('iata', $iataOrIcao)
            ->orWhere('icao', $iataOrIcao)
            ->first();

        $id = $row?->id ?? $iataOrIcao;
        // StaticController::dataRedis کماکان صدا زده می‌شود چون منطق cache آن جاست
        return app(\App\Http\Controllers\Api\Panel\V2\StaticController::class)
            ->dataRedis('airline', $id);
    }

    public function getAircraft(string|int $id): array
    {
        $cached = Redis::get('aircraft:' . $id);
        if ($cached) {
            return json_decode($cached, true);
        }

        $row = DB::table('aircraft')->where('id', $id)->first();
        if (is_null($row)) {
            return ['iata' => $id];
        }

        $data = [
            'id'    => $row->id,
            'iata'  => $row->iata,
            'icao'  => $row->icao,
            'logo'  => $row->image,
            'model' => $row->model,
        ];
        Redis::set('aircraft:' . $id, json_encode($data));
        return $data;
    }

    public function getAirport(string $iata): array
    {
        $cached = Redis::get('airports:' . $iata);
        if ($cached) {
            return json_decode($cached, true);
        }

        $row = DB::table('airports')
            ->select(
                'airports.id as id',
                'airports.iata as iata',
                'airports.title as title',
                'airports.title_fa as title_fa',
                'airports.country as country',
                'airports.state as state',
                'airports.priority as priority',
                'airports.status as status',
                'countries.id as country_id',
                'countries.fa_name as country_title_fa',
                'countries.en_name as country_title_en',
                'states.id as state_id',
                'states.fa_name as state_title_fa',
                'states.en_name as state_title_en',
                'cities.id as city_id',
                'cities.fa_name as city_title_fa',
                'cities.en_name as city_title_en',
            )
            ->where('airports.iata', $iata)
            ->leftJoin('countries', 'countries.id', 'airports.country')
            ->leftJoin('states', 'states.id', 'airports.state')
            ->leftJoin('cities', 'cities.id', 'airports.city')
            ->first();

        $data = [
            'id'       => $row->id,
            'iata'     => $row->iata,
            'title'    => $row->title,
            'title_fa' => $row->title_fa,
            'country'  => [
                'id'       => $row->country_id,
                'title_fa' => $row->country_title_fa,
                'title_en' => $row->country_title_en,
            ],
            'state'    => [
                'id'       => $row->state_id,
                'title_fa' => $row->state_title_fa,
                'title_en' => $row->state_title_en,
            ],
            'city'     => [
                'id'       => $row->city_id,
                'title_fa' => $row->city_title_fa,
                'title_en' => $row->city_title_en,
            ],
            'priority' => $row->priority,
            'status'   => $row->status,
        ];
        Redis::set('airports:' . $iata, json_encode($data));
        return $data;
    }

    public function getColleague(int $colleagueId): array
    {
        $cached = Redis::get('colleagues:' . $colleagueId);
        if ($cached) {
            return json_decode($cached, true);
        }

        $row = DB::table('colleagues')
            ->select('id', 'office as title_fa', 'office as title_en', 'first_name', 'last_name', 'credit_amount', 'status')
            ->where('id', $colleagueId)
            ->first();

        if (is_null($row)) {
            return [
                'id'            => $colleagueId,
                'title_fa'      => $colleagueId,
                'title_en'      => $colleagueId,
                'first_name'    => $colleagueId,
                'last_name'     => $colleagueId,
                'credit_amount' => $colleagueId,
                'status'        => $colleagueId,
            ];
        }

        $data = (array) $row;
        Redis::set('colleagues:' . $colleagueId, json_encode($data));
        return $data;
    }
}