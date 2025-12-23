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
        'flight_date',
        'flight_class',
        'class_status',
        'available_seats',
        'price_adult',
        'price_child',
        'price_infant',
        'aircraft_type',
        // 'currency',
        'status',
        'update_priority',
        'last_updated_at',
        'raw_data',
        // 'extra_detail_id', 
    ];

    protected $casts = [
        'departure_datetime' => 'datetime',
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

    // public function extraDetail()
    // {
    //     return $this->belongsTo(ExtraDetail::class, 'extra_detail_id');
    // }

    // public function getArrivalDatetimeAttribute()
    // {
    //     return $this->extraDetail?->arrival_datetime;
    // }

    // Helper Methods
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