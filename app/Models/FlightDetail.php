<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightDetail extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'flight_id',
        'arrival_datetime',
        'has_transit',
        'transit_city',
        'operating_airline',
        'operating_flight_no',
        'refund_rules',
        'baggage_weight',
        'baggage_pieces',
    ];

    protected $casts = [
        'arrival_datetime' => 'datetime',
        'has_transit' => 'boolean',
    ];

    public function flight()
    {
        return $this->belongsTo(Flight::class);
    }

    public function getFlightDurationAttribute(): ?int
    {
        if (!$this->arrival_datetime) return null;
        
        return $this->flight->departure_datetime
            ->diffInMinutes($this->arrival_datetime);
    }
}
