<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Flight extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'airline_active_route_id',
        'flight_number',
        'departure_datetime',
        'aircraft_type',
        'update_priority',
        'missing_count',  
        'last_updated_at',
    ];

    protected $casts = [
        'departure_datetime' => 'datetime',
        'last_updated_at' => 'datetime',
        'missing_count' => 'integer',
    ];

    public function route()
    {
        return $this->belongsTo(AirlineActiveRoute::class, 'airline_active_route_id');
    }

    public function classes()
    {
        return $this->hasMany(FlightClass::class);
    }

    public function details()
    {
        return $this->hasOne(FlightDetail::class);
    }

    // Helpers
    public function getFlightDateAttribute(): string
    {
        return $this->departure_datetime->toDateString();
    }

    public function getDaysUntilDeparture(): int
    {
        return now()->diffInDays($this->departure_datetime, false);
    }

    public function calculatePriority(): int
    {
        $days = $this->getDaysUntilDeparture();
        
        if ($days <= 3) return 1;
        if ($days <= 7) return 2;
        if ($days <= 30) return 3;
        return 4;
    }


    public function isMissing(): bool
    {
        return $this->missing_count > 0;
    }


    public function shouldBeDeleted(): bool
    {
        return $this->missing_count >= 2;
    }

    public function scopeUpcoming($query)
    {
        return $query->where('departure_datetime', '>=', now());
    }

    public function scopeByPriority($query, int $priority)
    {
        return $query->where('update_priority', $priority);
    }

    public function scopeOnDate($query, Carbon $date)
    {
        return $query->whereDate('departure_datetime', $date->toDateString());
    }

    public function scopeMissing($query)
    {
        return $query->where('missing_count', '>', 0);
    }
}