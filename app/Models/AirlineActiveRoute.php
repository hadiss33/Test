<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class AirlineActiveRoute extends Model
{
    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    protected $fillable = [
        'iata',
        'origin',
        'destination',
        'application_interfaces_id',
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    protected $casts = [
        'monday' => 'boolean',
        'tuesday' => 'boolean',
        'wednesday' => 'boolean',
        'thursday' => 'boolean',
        'friday' => 'boolean',
        'saturday' => 'boolean',
        'sunday' => 'boolean',
        'updated_at' => 'datetime',
    ];

    public function flights()
    {
        return $this->hasMany(Flight::class);
    }

    public function hasFlightOnDate(Carbon $date): bool
    {
        $dayName = strtolower($date->englishDayOfWeek);

        return $this->{$dayName} === true;
    }

    // public function scopeForService($query, string $service)
    // {
    //     return $query->where('service', $service);
    // }

    public function scopeForAirline($query, string $iata)
    {
        return $query->where('iata', $iata);
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('monday', true)
                ->orWhere('tuesday', true)
                ->orWhere('wednesday', true)
                ->orWhere('thursday', true)
                ->orWhere('friday', true)
                ->orWhere('saturday', true)
                ->orWhere('sunday', true);
        });
    }

    public function applicationInterface()
    {
        return $this->belongsTo(
            ApplicationInterface::class,
            'application_interfaces_id'
        );
    }
}
