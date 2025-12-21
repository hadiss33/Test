<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flight extends Model
{
    protected $fillable = [
        'airline_active_route_id',
        'flight_number', 'departure_datetime', 'flight_date',
        'arrival_datetime', 'flight_class', 'class_status',
        'aircraft_type', 'currency',
        'price_adult', 'price_child', 'price_infant',
        'available_seats', 'status', 'update_priority',
        'last_updated_at', 'raw_data',
    ];

    protected $casts = [
        'departure_datetime' => 'datetime',
        'arrival_datetime' => 'datetime',
        'flight_date' => 'date',
        'price_adult' => 'decimal:2',
        'price_child' => 'decimal:2',
        'price_infant' => 'decimal:2',
        'last_updated_at' => 'datetime',
        'raw_data' => 'array',
    ];

    public function route()
    {
        return $this->belongsTo(AirlineActiveRoute::class, 'airline_active_route_id');
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

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'active')
            ->where('available_seats', '>', 0);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('departure_datetime', '>=', now());
    }

    public function scopeByPriority($query, int $priority)
    {
        return $query->where('update_priority', $priority);
    }
}