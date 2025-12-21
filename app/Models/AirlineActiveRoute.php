<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;


class AirlineActiveRoute extends Model
{
   protected $fillable = [
        'airline_code',
        'origin',
        'destination',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    public function flights()
    {
        return $this->hasMany(Flight::class);
    }

    public function hasFlightOnDate(Carbon $date): bool
    {
        $dayName = strtolower($date->englishDayOfWeek);
        return $this->{$dayName} > 0;
    }

    public function getFlightCountForDate(Carbon $date): int
    {
        $dayName = strtolower($date->englishDayOfWeek);
        return $this->{$dayName} ?? 0;
    }

    public function scopeForAirline($query, string $airlineCode)
    {
        return $query->where('airline_code', $airlineCode);
    }
}
