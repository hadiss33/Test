<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class AirlineActiveRoute extends Model
{
    const UPDATED_AT = 'updated_at';
    const CREATED_AT = null;

    protected $fillable = [
        'iata',        
        'origin',
        'destination',
        'service',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    protected $casts = [
        'monday' => 'integer',
        'tuesday' => 'integer',
        'wednesday' => 'integer',
        'thursday' => 'integer',
        'friday' => 'integer',
        'saturday' => 'integer',
        'sunday' => 'integer',
    ];

    public function flights()
    {
        return $this->hasMany(Flight::class);
    }

    public function getAirlineCodeAttribute()
    {
        return $this->iata;
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

    public function scopeForService($query, string $service)
    {
        return $query->where('service', $service);
    }

    public function scopeForAirline($query, string $iata)
    {
        return $query->where('iata', $iata);
    }
}