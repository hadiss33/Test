<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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

        return $query->where($priorityCurrent, $priority);
    }

    public function scopeOnDate($query, Carbon $date)
    {
        return $query->whereDate('departure_datetime', $date->toDateString());
    }

    public function scopeMissing($query)
    {
        return $query->where('missing_count', '>', 0);
    }

    public const RELATION_MAP = [
        'Rule' => 'classes.rules',
        'FareBreakdown' => 'classes.fareBreakdown',
        'Tax' => 'classes.taxes',        
        'TaxDetails' => 'classes.taxes',    
        'Baggage' => 'classes.fareBaggage',
    ];


    public function scopeFilter(Builder $query, array $filters): Builder
    {
        if (! empty($filters['from_date'])) {
            $query->whereDate('departure_datetime', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereHas('details', function ($q) use ($filters) {
                $q->whereDate('arrival_datetime', $filters['to_date']);
            });
        }

        if (! empty($filters['origin']) || ! empty($filters['destination']) || ! empty($filters['airline'])) {
            $query->whereHas('route', function ($q) use ($filters) {
                if (! empty($filters['origin'])) {
                    $q->where('origin', $filters['origin']);
                }
                if (! empty($filters['destination'])) {
                    $q->where('destination', $filters['destination']);
                }
                if (! empty($filters['airline'])) {
                    $q->where('iata', $filters['airline']);
                }
            });
        }

        return $query;
    }

}
