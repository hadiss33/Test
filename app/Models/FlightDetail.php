<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightDetail extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'flight_id',
        'arrival_datetime',
        'aircraft_code',
        'aircraft_type_code',
        'last_updated_at'

    ];

    protected $casts = [
        'arrival_datetime' => 'datetime',
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
