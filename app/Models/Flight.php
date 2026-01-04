<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Flight extends Model
{
    
    
    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    protected $fillable = [
        'airline_active_route_id',
        'flight_number',
        'departure_datetime',
        'missing_count',
        'updated_at',
    ];

    protected $casts = [
        'departure_datetime' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function route()
    {
        return $this->belongsTo(AirlineActiveRoute::class, 'airline_active_route_id');
    }

    public function activeRoute() 
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

        if ($days <= 3) {
            return 1;
        }
        if ($days <= 7) {
            return 2;
        }
        if ($days <= 30) {
            return 3;
        }

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
        $priorityCurrent = $this->calculatePriority(); 
        return $query->where($priorityCurrent , $priority);
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
